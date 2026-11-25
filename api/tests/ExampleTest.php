<?php

namespace Tests;

class ExampleTest extends TestCase
{
    /**
     * Verifica que el endpoint base de la API responda correctamente.
     *
     * @return void
     */
    public function test_that_base_endpoint_returns_api_ok()
    {
        $this->get('/');

        $this->assertResponseOk();
        $this->assertEquals(
            'API OK',
            $this->response->getContent()
        );
    }
}
