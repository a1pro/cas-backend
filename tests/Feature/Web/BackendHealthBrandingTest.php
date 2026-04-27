<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class BackendHealthBrandingTest extends TestCase
{
    public function test_root_health_message_uses_talk_to_cas_branding(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('message', 'TALK to CAS backend is live.');
    }
}
