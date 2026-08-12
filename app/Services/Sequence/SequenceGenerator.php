<?php

namespace App\Services\Sequence;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only permitted reader of `sequences` — adr/0003 decision 9, schema.md.
 *
 * ⚠ EVERY CLAIM TAKES THE ROW WITH lockForUpdate(), INSIDE THE CALLER'S TRANSACTION.
 *
 * `MAX() + 1` is not acceptable and neither is an unlocked read of `next_value`: both
 * collide the moment two requests read before either writes — a double-clicked Save button,
 * two open tabs, a legacy import running beside manual entry, a seeder. The row lock is what
 * makes the second request WAIT rather than read the same number.
 *
 * ⚠ The client's operating rule that ONE HR DOES ALL REGISTRATION does not remove the need.
 * That rule prevents duplicate PEOPLE; this prevents duplicate NUMBERS. They are
 * complementary, not alternatives — one careless double-click is enough.
 *
 * ⚠ THE SEQUENCE NEVER REWINDS. `next_value` only ever increases. A number retired with a
 * departing employee, or vacated by a Master Admin correction, is burned rather than
 * returned to the pool: reissuing it would point previously printed letters and payslips at
 * the wrong person. There is deliberately no method here to release or reset a number.
 */
class SequenceGenerator
{
    /**
     * Claim the next number for a key.
     *
     * ⚠ Refuses outside a transaction, and that refusal is the guarantee. A lock taken
     * outside one is released the instant the statement finishes, so two callers would both
     * read the same value and both believe they held it — the exact collision this class
     * exists to prevent, with the appearance of protection. The number must also be able to
     * roll back with the row it numbers, or a failed insert would burn a number and leave a
     * visible gap.
     */
    public function next(string $key): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                "Refusing to claim a number for \"{$key}\" outside a transaction. The row lock "
                .'would be released immediately, so two callers could read the same value — '
                .'and the number could not roll back with the row it numbers '
                .'(adr/0003 decision 9). Wrap the insert in DB::transaction().'
            );
        }

        // firstOrCreate before the lock: a key claimed for the first time has no row to lock.
        // Racing here produces a unique-index violation on `key` rather than a duplicate
        // number, which is the safe direction — one caller retries, nobody gets a used
        // number.
        Sequence::query()->firstOrCreate(['key' => $key], ['next_value' => 1]);

        $sequence = Sequence::query()->where('key', $key)->lockForUpdate()->first();

        $claimed = $sequence->next_value;

        // Incremented under the same lock, so the next caller to acquire it reads the value
        // after this one — never alongside it.
        $sequence->next_value = $claimed + 1;
        $sequence->save();

        return $claimed;
    }

    /**
     * The next `employee_no`, formatted.
     *
     * ⚠ ALWAYS THE `AHS` PREFIX, regardless of which subsidiary employs the person. An AIM
     * employee is `AHS-0042`, not `AIM-0042`. This is counterintuitive enough to be
     * "corrected" by mistake, so: it is intentional. The unique index on
     * `employees.employee_no` is group-wide and not composite with `company_id`, and a
     * per-company counter would collide against it (employee-master.spec.md §10 decision 1,
     * BR-13).
     */
    public function nextEmployeeNo(): string
    {
        return 'AHS-'.str_pad((string) $this->next(Sequence::EMPLOYEE_NO), 4, '0', STR_PAD_LEFT);
    }
}
