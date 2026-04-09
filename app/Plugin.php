<?php

namespace BCC\Disputes;

use BCC\Disputes\Controllers\DisputeController;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    private ?DisputeController $controller = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function controller(): DisputeController
    {
        if ($this->controller === null) {
            $this->controller = new DisputeController();
        }
        return $this->controller;
    }
}
