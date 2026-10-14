<?php

declare(strict_types=1);

namespace Livewire\Features\SupportTesting;

use Illuminate\Testing\TestResponse;

/**
 * @method $this assertSuccessful()
 * @method $this assertOk()
 * @method $this assertStatus(int $status)
 * @method $this assertSeeLivewire(string $component)
 * @method $this assertDontSeeLivewire(string $component)
 *
 * @mixin TestResponse
 */
class Testable {}
