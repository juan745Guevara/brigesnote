<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_check_responde(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_pagina_de_inicio_responde(): void
    {
        $this->get('/')->assertOk();
    }
}
