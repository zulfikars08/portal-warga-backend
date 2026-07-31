<?php

namespace Tests\Feature;

use App\Http\Requests\{StoreExpenseRequest,StorePaymentRequest};
use App\Models\{Resident,Setting,User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UploadSettingLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function setLimit(string $key, int $kilobytes): void
    {
        Setting::create(['key'=>$key,'value'=>(string)$kilobytes,'type'=>'integer','group'=>'uploads']);
    }

    public function test_payment_proof_setting_changes_request_upload_limit(): void
    {
        $this->setLimit('payment_proof_max_kb', 1);
        $rules=(new StorePaymentRequest)->rules();
        $validator=Validator::make(['proofs'=>[['file'=>UploadedFile::fake()->create('proof.pdf',2,'application/pdf')]]],['proofs.*.file'=>$rules['proofs.*.file']]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('proofs.0.file',$validator->errors()->toArray());
    }

    public function test_expense_proof_setting_changes_request_upload_limit(): void
    {
        $this->setLimit('expense_proof_max_kb', 1);
        $rules=(new StoreExpenseRequest)->rules();
        $validator=Validator::make(['proofs'=>[['file'=>UploadedFile::fake()->create('proof.pdf',2,'application/pdf')]]],['proofs.*.file'=>$rules['proofs.*.file']]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('proofs.0.file',$validator->errors()->toArray());
    }

    public function test_resident_document_setting_changes_api_upload_limit(): void
    {
        $this->seed(InitialSeeder::class);
        Setting::where('key','resident_document_max_kb')->update(['value'=>'1']);
        $user=User::where('email','superadmin@portalwarga.test')->firstOrFail();
        $resident=Resident::create(['full_name'=>'Upload Test','marital_status'=>'MENIKAH','active'=>true]);

        $this->actingAs($user)->postJson("/api/v1/residents/{$resident->id}/documents",[
            'document_type'=>'KTP',
            'file'=>UploadedFile::fake()->create('ktp.pdf',2,'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }
}
