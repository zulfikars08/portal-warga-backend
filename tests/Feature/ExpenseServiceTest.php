<?php

namespace Tests\Feature;

use App\Models\{AuditLog,Expense,ExpenseCategory};
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;
    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->expenses = app(ExpenseService::class);
        $this->category = ExpenseCategory::create(['name' => 'Keamanan', 'active' => true]);
    }

    private function data(array $extra = []): array
    {
        return array_replace([
            'expense_category_id' => $this->category->id,
            'title' => 'Honor satpam',
            'description' => 'Honor Januari 2026',
            'amount' => 2500000,
            'spent_at' => '2026-01-31',
            'proofs' => [['file' => UploadedFile::fake()->create('kwitansi.pdf', 10, 'application/pdf'), 'metadata' => ['source' => 'test']]],
        ], $extra);
    }

    private function invalid(string $message, callable $call): void
    {
        try { $call(); $this->fail('ValidationException tidak dilempar.'); }
        catch (ValidationException $e) { $this->assertStringContainsString($message, collect($e->errors())->flatten()->implode(' ')); }
    }

    public function test_create_posts_expense_proof_and_audit(): void
    {
        $expense = $this->expenses->create($this->data(), null);

        $this->assertSame('POSTED', $expense->status);
        $this->assertSame(2500000, $expense->amount);
        $this->assertMatchesRegularExpression('/^EXP-\d{8}-[A-Z0-9]{8}$/', $expense->transaction_number);
        $this->assertSame('Keamanan', $expense->category->name);
        $this->assertSame(['source' => 'test'], $expense->proofs->first()->metadata);
        $this->assertArrayNotHasKey('storage_path', $expense->proofs->first()->toArray());
        Storage::disk('local')->assertExists(Expense::findOrFail($expense->id)->proofs()->firstOrFail()->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.created', 'auditable_id' => $expense->id]);
    }

    public function test_create_rejects_missing_proof_non_positive_amount_and_inactive_category(): void
    {
        $this->invalid('Bukti pengeluaran wajib', fn () => $this->expenses->create($this->data(['proofs' => []]), null));
        $this->invalid('lebih besar dari nol', fn () => $this->expenses->create($this->data(['amount' => 0]), null));
        $this->category->update(['active' => false]);
        $this->invalid('tidak aktif', fn () => $this->expenses->create($this->data(), null));
        $this->assertSame(0, Expense::count());
    }

    public function test_cancel_records_reason_audit_and_cannot_repeat(): void
    {
        $expense = $this->expenses->create($this->data(), null);
        $cancelled = $this->expenses->cancel($expense, 'Salah nominal', null);

        $this->assertSame('CANCELLED', $cancelled->status);
        $this->assertSame('Salah nominal', $cancelled->cancel_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(1, AuditLog::where('action', 'expense.cancelled')->count());
        $this->invalid('sudah dibatalkan', fn () => $this->expenses->cancel($expense, 'Lagi', null));
    }

    public function test_cancelled_expense_prefills_and_can_be_replaced_once(): void
    {
        $original = $this->expenses->create($this->data(), null);
        $this->invalid('harus sudah dibatalkan', fn () => $this->expenses->prefill($original));
        $this->expenses->cancel($original, 'Koreksi', null);

        $prefill = $this->expenses->prefill($original->fresh());
        $this->assertSame($original->id, $prefill['replaces_expense_id']);
        $this->assertSame(2500000, $prefill['amount']);

        $replacement = $this->expenses->create($this->data(['title' => 'Honor satpam revisi']), null, $original);
        $this->assertSame($original->id, $replacement->replaces_expense_id);
        $this->assertSame($replacement->id, $original->fresh()->replacement->id);
        $this->invalid('sudah memiliki pengeluaran pengganti', fn () => $this->expenses->create($this->data(), null, $original));
    }

    public function test_category_can_only_be_deleted_before_use(): void
    {
        $unused = ExpenseCategory::create(['name' => 'Lainnya', 'active' => true]);
        $this->expenses->deleteCategory($unused);
        $this->assertModelMissing($unused);

        $this->expenses->create($this->data(), null);
        $this->invalid('tidak dapat dihapus', fn () => $this->expenses->deleteCategory($this->category));
        $this->assertModelExists($this->category);
    }
}
