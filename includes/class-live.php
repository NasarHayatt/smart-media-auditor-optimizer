<?php
/**
 * Compatibility shim for the 1.x file layout.
 *
 * 2.0 moved the classes into includes/<module>/. If a stale 1.x bootstrap is
 * still executing, for example because an opcode cache is serving the previous
 * plugin file, its autoloader looks for this path and cannot resolve the newer
 * class names. Installing the modern autoloader here keeps the site working
 * instead of raising a fatal for classes that merely moved.
 *
 * Safe to delete once every install is running 2.x.
 *
 * @package SMAO
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/admin/class-live.php';
