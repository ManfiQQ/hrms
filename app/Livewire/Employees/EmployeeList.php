<?php

namespace App\Livewire\Employees;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\Auth\ReadScopeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The employee list — `employee-master.spec.md` §5.4 and §7.
 *
 * ⚠ THE COMPONENT HOLDS NO VISIBILITY LOGIC. Which employees this account may see is
 * `Employee::scopeVisibleTo()`, which expresses the same rule `EmployeePolicy::view()` enforces
 * per record; `EmployeeListVisibilityTest` compares the two. What lives here is the reader's
 * own choices — a search term and seven filters — and those narrow the visible set further.
 * They can never widen it.
 *
 * ⚠ THE LIST IS THE ONE PLACE A PERMISSION FAILURE IS SILENT. A missing policy check on a
 * detail screen produces a refusal somebody notices; a missing narrowing here just renders
 * more rows, correctly formatted, with nothing to report. That is why the narrowing is a
 * tested scope rather than a `where` written in this file.
 */
class EmployeeList extends Component
{
    use WithPagination;

    /**
     * Twenty-five rows a page.
     *
     * §5.4 says "paginated" and names no size; this is a usage decision, taken 2026-08-15.
     * Part of this workforce reads on a phone, and HR scans by name — 25 is one screen on a
     * desktop and a short scroll on a handset.
     */
    public const PER_PAGE = 25;

    public string $search = '';

    /** The seven filters §5.4 names. Empty string means "not chosen" — see filters(). */
    public string $companyId = '';

    public string $branchId = '';

    public string $departmentId = '';

    public string $positionId = '';

    public string $staffStatus = '';

    public string $employmentType = '';

    public string $level = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Employee::class);
    }

    /**
     * ⚠ Re-authorised on every render, not only on mount. A Livewire component is a long-lived
     * object reachable by crafted requests, so a mount-time check would authorise once for the
     * life of the page — the same reasoning `ManageAccount` carries, and it applies to a read
     * screen as much as to a write one.
     */
    public function render()
    {
        Gate::authorize('viewAny', Employee::class);

        return view('livewire.employees.employee-list', [
            'employees' => $this->employees(),
        ]);
    }

    /**
     * ⚠ Any change to a search term or a filter returns to page one. Without this, narrowing a
     * result set while on page 4 shows an empty table with a working paginator — which reads
     * as "no employees match" when the truth is "no page 4".
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    /** Every filter cleared. The visibility scope is untouched by this — it is not a filter. */
    public function clearFilters(): void
    {
        $this->reset(['search', 'companyId', 'branchId', 'departmentId', 'positionId', 'staffStatus', 'employmentType', 'level']);
        $this->resetPage();
    }

    /**
     * ⚠ THE COMPANY FILTER IS HIDDEN WHEN THE ACCOUNT READS ONE COMPANY, and the two form
     * layouts that result are deliberate.
     *
     * A select with a single option is a control that cannot change the answer — a UI that
     * lies about its own scope — and a read-only label saying "Company: AIM" answers a
     * question nobody asked, since an AIM supervisor knows they are at AIM. **So a
     * group-scoped account and a subsidiary-scoped account see different forms.** Do not
     * "tidy" this into one form for both: that restores the dead control, which is the thing
     * being avoided.
     */
    public function getShowsCompanyFilterProperty(): bool
    {
        return count($this->readableCompanyIds()) > 1;
    }

    /**
     * Whether the reader has narrowed anything — which is what separates the two empty states.
     *
     * "No employees match these filters" and "no employees to show" mean different things, and
     * the second one carries information: for a supervisor it is `adr/0011` decision 4 in
     * plain sight, meaning nobody's BR-8 columns name them.
     */
    public function getHasActiveFiltersProperty(): bool
    {
        return trim($this->search) !== '' || array_filter($this->filters()) !== [];
    }

    /** @return Collection<int, Company> */
    public function getCompaniesProperty()
    {
        return Company::query()->whereIn('id', $this->readableCompanyIds())->orderBy('name')->get();
    }

    /**
     * Filter vocabularies. Branch and Department carry `SharedTenantScope`, so a shared org
     * unit (`company_id IS NULL`) appears for every account — that is `adr/0002`, and an
     * employee of TURSENIA sitting in a shared branch is a correct record (BR-12).
     *
     * @return Collection<int, Branch>
     */
    public function getBranchesProperty()
    {
        return Branch::query()->orderBy('name')->get();
    }

    /** @return Collection<int, Department> */
    public function getDepartmentsProperty()
    {
        return Department::query()->orderBy('name')->get();
    }

    /** @return Collection<int, Position> */
    public function getPositionsProperty()
    {
        return Position::query()->orderBy('title')->get();
    }

    /**
     * The list itself.
     *
     * ⚠ Order of composition matters and is not cosmetic: `visibleTo()` first, then the
     * reader's own narrowing. `matchingSearch()` groups its four `orWhere`s so they cannot
     * escape the conditions already applied.
     *
     * Sorted by `full_name` ascending — HR looks people up by name (decision 2026-08-15).
     */
    private function employees()
    {
        return Employee::query()
            ->visibleTo(auth()->user())
            ->matchingSearch($this->search)
            ->filteredBy($this->filters())
            // Eager loaded because the table renders three of them per row; without this the
            // page issues three queries per employee and nothing looks wrong while it does.
            ->with(['company:id,name', 'department:id,name', 'position:id,title'])
            ->orderBy('full_name')
            ->paginate(self::PER_PAGE);
    }

    /**
     * ⚠ THE COLUMN NAMES ARE WRITTEN HERE, NOT TAKEN FROM THE REQUEST. Only the values come
     * from the form. A request-supplied key would let a crafted filter query any column on the
     * table — `ic_no`, `bank_account_no` — and turn the list into an oracle answering whether
     * a value exists.
     *
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'company_id' => $this->showsCompanyFilter ? $this->companyId : '',
            'branch_id' => $this->branchId,
            'department_id' => $this->departmentId,
            'position_id' => $this->positionId,
            'staff_status' => $this->staffStatus,
            'employment_type' => $this->employmentType,
            'level' => $this->level,
        ];
    }

    /** @return list<int> */
    private function readableCompanyIds(): array
    {
        return app(ReadScopeResolver::class)->resolve(auth()->user());
    }
}
