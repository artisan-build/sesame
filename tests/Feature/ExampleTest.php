<?php

test('returns a successful response', function (): void {
    $response = $this->get(route('bfc.landing'));

    $response->assertOk();
});
