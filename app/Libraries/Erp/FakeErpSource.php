<?php

namespace App\Libraries\Erp;

/**
 * An in-memory ERP for the self-test (php spark portal:test). Rows use the same logical keys as ErpDirectory.
 * Set ->down = true (or ->hostelDown) to simulate an unreachable ERP.
 */
class FakeErpSource
{
    /** @var list<array<string,mixed>> */
    public array $students = [];
    /** @var list<array<string,mixed>> */
    public array $employees = [];
    /** @var array<string,list<array<string,mixed>>> studentuid => current hostel rows */
    public array $hostels = [];
    public bool $down       = false;
    public bool $hostelDown = false;
    public int $calls       = 0;   // how many times the ERP was asked (to prove the session cache works)

    public function studentsByRoll(string $roll): array
    {
        $this->calls++;
        if ($this->down) {
            throw new ErpUnavailable('fake ERP is down');
        }

        return array_values(array_filter($this->students, static fn ($s) => strtoupper(trim((string) $s['roll'])) === strtoupper(trim($roll))));
    }

    public function employeesByEmail(string $email): array
    {
        $this->calls++;
        if ($this->down) {
            throw new ErpUnavailable('fake ERP is down');
        }

        return array_values(array_filter($this->employees, static fn ($e) => strtolower(trim((string) $e['email'])) === strtolower(trim($email))));
    }

    public function hostelFor(string $studentUid): array
    {
        $this->calls++;
        if ($this->down || $this->hostelDown) {
            throw new ErpUnavailable('fake hostel database is down');
        }

        return $this->hostels[$studentUid] ?? [];
    }

    public function ping(): array
    {
        return ['main' => ['ok' => ! $this->down, 'detail' => 'fake'], 'hostel' => ['ok' => ! $this->down && ! $this->hostelDown, 'detail' => 'fake']];
    }

    public function allStudents(): array
    {
        $this->calls++;
        if ($this->down) {
            throw new ErpUnavailable('fake ERP is down');
        }

        return $this->students;
    }

    public function allEmployees(): array
    {
        $this->calls++;
        if ($this->down) {
            throw new ErpUnavailable('fake ERP is down');
        }

        return $this->employees;
    }

}
