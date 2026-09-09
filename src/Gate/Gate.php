<?php
/**
 * A única decisão de bloqueio do sistema.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Gate;

use WpRecaptchaForms\Frontend\Messages;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\ProviderInterface;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Provider\RejectionReason;
use WpRecaptchaForms\Provider\VerificationRequest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Princípio A1: existe exatamente um lugar que decide "passa ou não passa".
 *
 * Clássico e Blocks, comentário e login, Woo e Newsletter compartilham `assess()`.
 * Todo o resto do plugin é adaptador.
 */
final class Gate {

	/** Mínimo de avaliações antes de o advisory de par cruzado poder disparar. */
	const KEYPAIR_MIN_SAMPLE = 10;

	/** Transient com o total de avaliações de token não-vazio. */
	const TRANSIENT_KEYPAIR_TOTAL = 'wp_recaptcha_forms_keypair_total';

	/** Transient com o total de recusas por token inválido. */
	const TRANSIENT_KEYPAIR_INVALID = 'wp_recaptcha_forms_keypair_invalid';

	/**
	 * Provider ativo.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Memoização por hash do token, dentro do request.
	 *
	 * @var array<string, Verdict>
	 */
	private $memo = array();

	/**
	 * Construtor.
	 *
	 * @param ProviderInterface $provider Provider ativo.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Avalia uma submissão.
	 *
	 * Ordem normativa da arquitetura v1.1 §7.2. Sequencial, primeira condição que casar
	 * decide. Qualquer outra ordem produz um bug diferente.
	 *
	 * @param FormContext $context Contexto.
	 * @return Verdict
	 */
	public function assess( FormContext $context ): Verdict {
		// 1. Toggle do formulário desligado: não é falha, não conta em lugar nenhum.
		if ( ! $this->should_protect( $context ) ) {
			return Verdict::allow();
		}

		// 2. Memoização. Antes do passo 4 de propósito: sob dois hooks do mesmo request,
		// a mesma submissão precisa produzir o mesmo veredito — e um token do provedor
		// só pode ser verificado uma vez (arquitetura v1 §6.4).
		$key = hash( 'sha256', $context->token() );

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		// 3. Secret ausente: erro do operador, e sem gastar rede.
		if ( '' === Options::secret_key() ) {
			return $this->remember( $key, $this->decide( FailureClass::MISCONFIG, $context, null, array( 'secret:missing' ) ) );
		}

		// 4. Token vazio: decide sem gastar rede.
		if ( '' === $context->token() ) {
			if ( ClientState::is_unreachable( $context->client_state() ) ) {
				// 4a. O cliente declarou que não alcançou o provedor.
				return $this->remember( $key, $this->decide( FailureClass::CLIENT_UNREACHABLE, $context, $context->client_state() ) );
			}

			// 4b. Nada de token e nenhuma declaração: é o que um bot produz.
			return $this->remember( $key, $this->decide( FailureClass::REJECTED, $context, RejectionReason::MISSING_TOKEN ) );
		}

		// 5-6. Chama o provider e classifica.
		$response = $this->provider->verify( $this->build_request( $context ) );

		$this->record_keypair_sample( $response );

		if ( $response->is_success() ) {
			// Uma verificação que funcionou prova que a configuração está boa: o notice
			// persistente some quando o problema é corrigido, não quando o operador se irrita.
			if ( get_option( Options::OPTION_MISCONFIG_SINCE, '' ) ) {
				self::clear_misconfig();
			}

			return $this->remember( $key, Verdict::allow() );
		}

		$failure = $response->failure();

		if ( ! FailureClass::is_valid( $failure ) ) {
			$failure = FailureClass::INFRA;
		}

		// 7. Política efetiva e Verdict imutável.
		return $this->remember( $key, $this->decide( $failure, $context, $response->reason(), $response->debug_codes() ) );
	}

	/**
	 * O formulário deve ser protegido?
	 *
	 * @param FormContext $context Contexto.
	 * @return bool
	 */
	private function should_protect( FormContext $context ): bool {
		$enabled = Options::form_enabled( $context->form_id() );

		/**
		 * Desliga a proteção por contexto.
		 *
		 * @param bool        $enabled Estado configurado.
		 * @param string      $form_id Formulário.
		 * @param FormContext $context Contexto.
		 */
		return (bool) apply_filters( 'wp_recaptcha_forms_should_protect', $enabled, $context->form_id(), $context );
	}

	/**
	 * Monta a requisição de verificação.
	 *
	 * @param FormContext $context Contexto.
	 * @return VerificationRequest
	 */
	private function build_request( FormContext $context ): VerificationRequest {
		return new VerificationRequest(
			$context->token(),
			Options::secret_key(),
			Options::send_remote_ip() ? $context->remote_ip() : null,
			$context->action(),
			null,
			Options::threshold()
		);
	}

	/**
	 * Aplica a política e monta o veredito.
	 *
	 * @param string      $failure Classe de falha.
	 * @param FormContext $context Contexto.
	 * @param string|null $reason  Motivo.
	 * @param string[]    $debug   Diagnóstico.
	 * @return Verdict
	 */
	private function decide( string $failure, FormContext $context, ?string $reason = null, array $debug = array() ): Verdict {
		if ( FailureClass::MISCONFIG === $failure ) {
			$this->escalate_misconfig( $debug );
		}

		$policy = FailurePolicy::resolve( $failure, $context );

		if ( FailurePolicy::ALLOW === $policy ) {
			return new Verdict( true, $failure, '', $reason, $debug );
		}

		$verdict = new Verdict(
			false,
			$failure,
			Messages::for_failure( $failure, $context->form_id(), $reason ),
			$reason,
			$debug
		);

		/**
		 * Inspeção ou override final do veredito. Poder máximo, documentado como tal.
		 *
		 * @param Verdict     $verdict Veredito.
		 * @param FormContext $context Contexto.
		 */
		$filtered = apply_filters( 'wp_recaptcha_forms_verdict', $verdict, $context );

		return $filtered instanceof Verdict ? $filtered : $verdict;
	}

	/**
	 * Memoriza e devolve.
	 *
	 * @param string  $key     Chave.
	 * @param Verdict $verdict Veredito.
	 * @return Verdict
	 */
	private function remember( string $key, Verdict $verdict ): Verdict {
		$this->memo[ $key ] = $verdict;

		return $verdict;
	}

	/**
	 * Grava o estado de MISCONFIG que a UI e o Site Health consomem.
	 *
	 * Quatro camadas de escalada (arquitetura v1 §5.3, disparo corrigido na v1.1 §4.3).
	 * Aqui mora só o dado; notice, Site Health e o estado no topo da tela são da UI.
	 *
	 * @param string[] $debug Códigos de diagnóstico.
	 * @return void
	 */
	private function escalate_misconfig( array $debug ): void {
		if ( ! get_option( Options::OPTION_MISCONFIG_SINCE, '' ) ) {
			update_option( Options::OPTION_MISCONFIG_SINCE, time() );
		}

		update_option( Options::OPTION_MISCONFIG_CODES, $debug );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Nunca contém a secret.
			error_log( 'wp-recaptcha-forms: configuração inválida (' . implode( ',', $debug ) . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Limpa o estado de MISCONFIG. Chamado quando uma verificação volta a funcionar.
	 *
	 * @return void
	 */
	public static function clear_misconfig(): void {
		delete_option( Options::OPTION_MISCONFIG_SINCE );
		delete_option( Options::OPTION_MISCONFIG_CODES );
	}

	/**
	 * Advisory de par de chaves cruzado (arquitetura v1.1 §4.4).
	 *
	 * Dois contadores em transient de 1 hora. Se, num mínimo de 10 avaliações de token
	 * não-vazio, 100% resultarem em token inválido, a causa mais provável é a site key e
	 * a secret pertencerem a projetos diferentes.
	 *
	 * NENHUM veredito muda aqui. É diagnóstico puro: um site legítimo sob ataque massivo
	 * pode disparar o aviso, e um aviso a mais num site sob ataque não faz mal a ninguém,
	 * enquanto um site travado sem aviso faz.
	 *
	 * @param ProviderResponse $response Resposta do provider.
	 * @return void
	 */
	private function record_keypair_sample( ProviderResponse $response ): void {
		if ( ! $response->reached_provider() ) {
			return;
		}

		$total = (int) get_transient( self::TRANSIENT_KEYPAIR_TOTAL );
		$bad   = (int) get_transient( self::TRANSIENT_KEYPAIR_INVALID );

		++$total;

		if ( RejectionReason::INVALID_TOKEN === $response->reason() ) {
			++$bad;
		}

		set_transient( self::TRANSIENT_KEYPAIR_TOTAL, $total, HOUR_IN_SECONDS );
		set_transient( self::TRANSIENT_KEYPAIR_INVALID, $bad, HOUR_IN_SECONDS );

		if ( $total >= self::KEYPAIR_MIN_SAMPLE && $total === $bad ) {
			update_option( Options::OPTION_KEYPAIR_SUSPECT, time() );

			return;
		}

		if ( $bad < $total ) {
			// Alguma verificação passou pelo provedor sem ser recusada: o par não está cruzado.
			delete_option( Options::OPTION_KEYPAIR_SUSPECT );
		}
	}
}
