<?php
/**
 * Sanitização das opções vindas da tela de configurações.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Admin;

use WpRecaptchaForms\Gate\FailurePolicy;
use WpRecaptchaForms\Integrations\Registry;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Telemetry\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nada entra na option sem passar por aqui.
 */
final class Sanitizer {

	/**
	 * Guarda de reentrância (bug real de produção, memory limit estourado em `Options.php`).
	 *
	 * `register_setting()` liga este método ao filtro `sanitize_option_{OPTION}`, e o
	 * WordPress dispara esse filtro em TODO `update_option()` daquela opção — não só no
	 * POST da tela. `apply_telemetry_consent()` grava a option de novo (via
	 * `Consent::grant()/revoke()` → `Options::update()`) enquanto ainda está DENTRO desta
	 * própria chamada, o que reaciona o `sanitize_callback` e recursaria infinitamente sem
	 * este guard.
	 *
	 * @var bool
	 */
	private static bool $sanitizing = false;

	/**
	 * Sanitiza o array inteiro.
	 *
	 * @param mixed $input Entrada crua da Settings API.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		if ( self::$sanitizing ) {
			// Chamada reentrante: `$input` já é o array que `Options::update()` acabou de
			// montar (já sanitizado), então devolvê-lo intacto é o comportamento correto do
			// filtro — e é isso que quebra o ciclo.
			return is_array( $input ) ? $input : Options::all();
		}

		self::$sanitizing = true;

		try {
			return self::do_sanitize( $input );
		} finally {
			self::$sanitizing = false;
		}
	}

	/**
	 * Lógica real de sanitização, isolada do guard de reentrância.
	 *
	 * @param mixed $input Entrada crua da Settings API.
	 * @return array
	 */
	private static function do_sanitize( $input ): array {
		$current = Options::all();
		$input   = is_array( $input ) ? $input : array();
		$clean   = Options::defaults();

		$clean['version'] = isset( $input['version'] ) && 'v2' === $input['version'] ? 'v2' : 'v3';

		$clean['site_key'] = isset( $input['site_key'] ) ? self::key( $input['site_key'] ) : '';

		/*
		 * Secret: campo mascarado. Salvar vazio MANTÉM o valor atual — se apagasse, todo
		 * save da tela desligaria a verificação, porque o campo nunca é ecoado em claro.
		 */
		$submitted_secret = isset( $input['secret_key'] ) ? self::key( $input['secret_key'] ) : '';

		if ( Options::secret_is_constant() ) {
			// A constante manda; não há o que gravar.
			$clean['secret_key'] = '';
		} elseif ( '' === $submitted_secret ) {
			$clean['secret_key'] = (string) ( $current['secret_key'] ?? '' );
		} else {
			$clean['secret_key'] = $submitted_secret;
		}

		$threshold = isset( $input['threshold'] ) ? (float) str_replace( ',', '.', (string) $input['threshold'] ) : 0.6;

		if ( $threshold < 0.0 || $threshold > 1.0 ) {
			$threshold = 0.6;
		}

		$clean['threshold'] = round( $threshold, 2 );

		$clean['remoteip'] = ! empty( $input['remoteip'] );

		$consent_mode         = isset( $input['consent_mode'] ) ? sanitize_key( $input['consent_mode'] ) : 'off';
		$clean['consent_mode'] = in_array( $consent_mode, array( 'off', 'auto', 'required' ), true ) ? $consent_mode : 'off';

		$clean['failure_policy_infra'] = self::policy(
			$input['failure_policy_infra'] ?? '',
			FailurePolicy::global_values(),
			FailurePolicy::ALLOW
		);

		$clean['failure_policy_client_unreachable'] = self::policy(
			$input['failure_policy_client_unreachable'] ?? '',
			FailurePolicy::global_values(),
			FailurePolicy::ALLOW
		);

		// Mensagens do operador são texto livre — logo, não confiáveis (v1 §13).
		foreach ( array_keys( $clean['messages'] ) as $key ) {
			$raw                    = isset( $input['messages'][ $key ] ) ? (string) wp_unslash( $input['messages'][ $key ] ) : '';
			$clean['messages'][ $key ] = trim( wp_kses( $raw, self::allowed_html() ) );
		}

		$clean['forms'] = self::forms( $input['forms'] ?? array(), $current['forms'] ?? array() );

		/*
		 * Telemetria: o toggle passa por `Telemetry\Consent`, NUNCA por escrita direta de
		 * `telemetry.enabled` aqui. É o `Consent` que gera e apaga o `instance_id`, agenda
		 * e desagenda o cron e apaga os contadores — um `$clean['telemetry']['enabled']`
		 * cru deixaria a instalação "ligada" sem identificador e sem cron, ou "desligada"
		 * com o identificador ainda no banco.
		 *
		 * O `Consent` escreve a option; logo abaixo relemos o resultado para dentro de
		 * `$clean`, porque a Settings API vai gravar `$clean` por cima assim que este
		 * callback retornar. Sem essa releitura, o save desfaria o que o `Consent` acabou
		 * de fazer.
		 */
		self::apply_telemetry_consent( $input );

		$clean['telemetry'] = Options::telemetry();

		return $clean;
	}

	/**
	 * Roteia o toggle de telemetria pelo ponto único de consentimento.
	 *
	 * @param array $input Entrada crua.
	 * @return void
	 */
	private static function apply_telemetry_consent( array $input ): void {
		// Sob constante de wp-config.php o checkbox é renderizado `disabled`, e um POST
		// forjado não pode contornar isso.
		if ( Options::telemetry_disabled_by_constant() ) {
			return;
		}

		if ( empty( $input['telemetry']['enabled'] ) ) {
			Consent::revoke();

			return;
		}

		Consent::grant();
	}

	/**
	 * Sanitiza a matriz de formulários.
	 *
	 * Preserva o estado de formulários que não vieram no POST: sem WooCommerce ativo a
	 * seção não é renderizada, e apagar o que ela guardava faria a reativação do Woo
	 * perder a configuração anterior (arquitetura v1 §7.3).
	 *
	 * @param mixed $input   Entrada.
	 * @param array $current Estado atual.
	 * @return array
	 */
	private static function forms( $input, array $current ): array {
		$input  = is_array( $input ) ? $input : array();
		$result = $current;

		$known = array_keys( Registry::instance()->all() );

		foreach ( $input as $form_id => $values ) {
			$form_id = sanitize_key( (string) $form_id );

			if ( '' === $form_id || ( ! empty( $known ) && ! in_array( $form_id, $known, true ) ) ) {
				continue;
			}

			$values = is_array( $values ) ? $values : array();

			$result[ $form_id ] = array(
				'enabled'                   => ! empty( $values['enabled'] ),
				'policy_infra'              => self::policy( $values['policy_infra'] ?? '', FailurePolicy::form_values(), FailurePolicy::INHERIT ),
				'policy_client_unreachable' => self::policy( $values['policy_client_unreachable'] ?? '', FailurePolicy::form_values(), FailurePolicy::INHERIT ),
			);
		}

		return $result;
	}

	/**
	 * Sanitiza um valor de política.
	 *
	 * @param mixed    $value    Valor.
	 * @param string[] $allowed  Valores aceitos.
	 * @param string   $fallback Default.
	 * @return string
	 */
	private static function policy( $value, array $allowed, string $fallback ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Sanitiza uma chave do provedor.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private static function key( $value ): string {
		$value = is_string( $value ) ? wp_unslash( $value ) : '';

		return trim( preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ) );
	}

	/**
	 * HTML permitido nas mensagens customizáveis.
	 *
	 * @return array
	 */
	private static function allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}
}
