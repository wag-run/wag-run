<?php

namespace App\Engine\Templates;

/**
 * Run data that is loaded only when an expression reads it, such as the
 * data store. DataBag::unwrap() turns it into the plain values it stands for.
 */
interface LazyData
{
    /**
     * @return array<array-key, mixed>
     */
    public function all(): array;
}
