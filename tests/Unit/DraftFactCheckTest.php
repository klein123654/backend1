<?php

namespace Tests\Unit;

use App\Support\DraftFactCheck;
use PHPUnit\Framework\TestCase;

class DraftFactCheckTest extends TestCase
{
    public function test_numbers_from_the_facts_pass_in_any_common_format(): void
    {
        $facts = ['₱18,500,000 total asking price', '2,400 sqm lot area', '40 m highway frontage'];

        $this->assertSame([], DraftFactCheck::unsupportedNumbers('Lot of 2400 sqm with 40 m frontage at ₱18.5 million (18,500,000).', $facts));
    }

    public function test_invented_numbers_are_reported_once_each(): void
    {
        $this->assertSame(['5', '10'], DraftFactCheck::unsupportedNumbers('Only 5 minutes away, 10 parking slots, 5 rooms.', ['120 sqm floor area']));
    }

    public function test_thousands_shorthand_is_allowed(): void
    {
        $this->assertSame([], DraftFactCheck::unsupportedNumbers('Rent is 45k a month.', ['₱45,000 per month']));
    }
}
