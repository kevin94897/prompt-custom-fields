<?php
/**
 * Clase base de reglas de ubicación (equivalente a ACF_Location).
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

abstract class PCF_Location {

	public $name     = '';
	public $label    = '';
	public $category = 'post';
	public $object_type = 'post';

	public function __construct() {
		$this->initialize();
	}

	abstract protected function initialize();

	/**
	 * @return bool
	 */
	abstract public function match( $rule, $screen, $group );

	/**
	 * Valores posibles (para la IA y la UI).
	 */
	public function get_values( $rule = array() ) {
		return array();
	}

	public function get_operators( $rule = array() ) {
		return array( '==' => 'es igual a', '!=' => 'no es igual a' );
	}

	public function describe() {
		return array(
			'param'     => $this->name,
			'label'     => $this->label,
			'category'  => $this->category,
			'operators' => array_keys( $this->get_operators() ),
			'values'    => $this->get_values(),
		);
	}

	/**
	 * Compara un valor (o lista) con la regla.
	 */
	protected function compare( $value, $rule ) {
		$values = array_map( 'strval', pcf_get_array( $value ) );
		$match  = in_array( (string) $rule['value'], $values, true );
		if ( '!=' === $rule['operator'] ) {
			return ! $match;
		}
		return $match;
	}
}
