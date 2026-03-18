<?php

namespace BCC\Disputes;

use BCC\Disputes\Application\Disputes\ResolveDisputeService;
use BCC\Disputes\Controllers\DisputeController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight service container for the Disputes plugin.
 *
 * Holds shared instances so that no class is instantiated more than once
 * per request. Not a full DI container — just a registry for the services
 * that are actually reused across hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    private ?DisputeController $controller = null;
    private ?ResolveDisputeService $resolveDisputeService = null;

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

    public function resolve_dispute_service(): ResolveDisputeService
    {
        if ($this->resolveDisputeService === null) {
            $this->resolveDisputeService = new ResolveDisputeService();
        }
        return $this->resolveDisputeService;
    }
}
