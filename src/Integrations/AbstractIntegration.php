<?php
/**
 * Base comum dos adaptadores.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations;

use WpRecaptchaForms\Frontend\FieldRenderer;
use WpRecaptchaForms\Gate\Verdict;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tudo que é comum aos nove adaptadores, e nada além disso.
 *
 * Um adaptador legítimo faz três coisas: imprime o campo, pergunta ao `Gate` e traduz o
 * `Verdict` para o idioma de erro do host. Se uma subclasse precisar de uma quarta, ela
 * provavelmente está tomando uma decisão que é do `Gate` (princípio A1).
 */
abstract class AbstractIntegration implements IntegrationInterface {

	/**
	 * Grupo na tela de configurações.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'core';
	}

	/**
	 * Disponível nesta instalação?
	 *
	 * @return bool
	 */
	public function available(): bool {
		return true;
	}

	/**
	 * Motivo da indisponibilidade.
	 *
	 * @return string
	 */
	public function unavailable_reason(): string {
		return '';
	}

	/**
	 * Action nomeada do v3 deste formulário.
	 *
	 * O v3 aceita `[A-Za-z/_]`; o id do formulário é `snake_case`, então o prefixo basta.
	 *
	 * @return string
	 */
	public function action(): string {
		return 'wrf_' . $this->id();
	}

	/**
	 * O operador ligou este formulário?
	 *
	 * @return bool
	 */
	protected function enabled(): bool {
		return Options::form_enabled( $this->id() );
	}

	/**
	 * Imprime os campos, se o formulário estiver ligado.
	 *
	 * O guard aqui não é redundante com o do `Gate`: sem ele, um formulário desligado
	 * ainda enfileiraria o script do provedor (o `FieldRenderer` chama o `AssetManager`),
	 * e FR-07 deixaria de valer justamente para quem escolheu não proteger nada.
	 *
	 * @return void
	 */
	public function render_field(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		FieldRenderer::render( $this->id(), $this->action() );
	}

	/**
	 * Avalia a submissão corrente.
	 *
	 * @return Verdict
	 */
	protected function assess(): Verdict {
		$context = Runtime::collector()->collect( $this->id(), $this->action() );

		return Runtime::gate()->assess( $context );
	}

	/**
	 * Código de erro específico da classe de falha, para hosts que distinguem por código.
	 *
	 * Um código genérico impediria o operador de separar "bot barrado" de "meu bloqueador
	 * quebrou meu login" — e `login_errors` é filtrável por temas e plugins de segurança
	 * (arquitetura v1.1 §6.4).
	 *
	 * @param Verdict $verdict Veredito bloqueado.
	 * @param string  $prefix  Prefixo do código (ex.: `wrf_login`).
	 * @return string
	 */
	protected function error_code( Verdict $verdict, string $prefix ): string {
		switch ( $verdict->failure() ) {
			case \WpRecaptchaForms\Provider\FailureClass::CLIENT_UNREACHABLE:
				return $prefix . '_client_unreachable';

			case \WpRecaptchaForms\Provider\FailureClass::INFRA:
			case \WpRecaptchaForms\Provider\FailureClass::MISCONFIG:
				return $prefix . '_unavailable';

			default:
				return $prefix . '_blocked';
		}
	}
}
