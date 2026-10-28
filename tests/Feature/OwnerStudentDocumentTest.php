<?php

namespace Tests\Feature;

use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Student Documents Vault (2026-10-28).
 *
 * Users resolved by EMAIL, never hardcoded ids. Every fake file
 * carries DISTINCT content (Laravel fake files of the same size
 * otherwise share identical content).
 *
 * Rules under test: upload is admin-only; list/download are
 * scoped (admin all, student own, parent own children, others
 * 403); files are pdf/doc/docx/jpg/jpeg/png, max 10 MB, stored
 * under documents/ with a random name; destroy removes row + file.
 */
class OwnerStudentDocumentTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->first();
    }

    protected function ownRecord()
    {
        return StudentRecord::where('user_id', $this->student()->id)->first();
    }

    /** A record belonging to neither the demo student nor the demo parent. */
    protected function foreignRecord()
    {
        return StudentRecord::where('user_id', '!=', $this->student()->id)
            ->where(function ($q) {
                $q->whereNull('my_parent_id')->orWhere('my_parent_id', '!=', $this->parent()->id);
            })->first();
    }

    protected function makeDoc($recordId, $title, $marker)
    {
        // Caller fakes the disk once per test — re-faking here would
        // wipe files created earlier in the same test.
        $path = 'documents/' . \Illuminate\Support\Str::random(40) . '.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 vault probe ' . $marker);
        return StudentDocument::create([
            'student_record_id' => $recordId,
            'uploaded_by' => $this->admin()->id,
            'title' => $title,
            'original_name' => 'probe-' . $marker . '.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
    }

    public function test_admin_upload_stores_random_documents_path()
    {
        Storage::fake('public');
        $response = $this->actingAs($this->admin())->post(route('documents.store'), [
            'student_record_id' => $this->ownRecord()->id,
            'title' => 'Vault upload probe',
            'document' => UploadedFile::fake()->createWithContent(
                'birth-certificate.pdf', "%PDF-1.4 vault upload unique-aaa\n"
            ),
        ]);
        $response->assertRedirect(route('documents.index'));
        $doc = StudentDocument::where('title', 'Vault upload probe')->first();
        $this->assertNotNull($doc);
        $this->assertStringStartsWith('documents/', $doc->stored_path);
        $this->assertStringNotContainsString('birth-certificate', $doc->stored_path);
        Storage::disk('public')->assertExists($doc->stored_path);
    }

    public function test_non_admin_upload_is_forbidden()
    {
        foreach ([$this->teacher(), $this->student(), $this->parent()] as $user) {
            $response = $this->actingAs($user)->post(route('documents.store'), [
                'student_record_id' => $this->ownRecord()->id,
                'title' => 'Forbidden probe ' . $user->email,
                'document' => UploadedFile::fake()->createWithContent(
                    'x.pdf', "%PDF-1.4 forbidden probe\n"
                ),
            ]);
            $response->assertForbidden();
            $this->assertNull(StudentDocument::where('title', 'Forbidden probe ' . $user->email)->first());
        }
        $this->actingAs($this->teacher())->get(route('documents.create'))->assertForbidden();
    }

    public function test_student_list_shows_only_own_documents()
    {
        Storage::fake('public');
        $own = $this->makeDoc($this->ownRecord()->id, 'Vault own probe', 'own-1');
        $foreign = $this->makeDoc($this->foreignRecord()->id, 'Vault foreign probe', 'for-1');
        $response = $this->actingAs($this->student())->get(route('documents.index'));
        $response->assertOk();
        $response->assertSee('Vault own probe');
        $response->assertDontSee('Vault foreign probe');
        $this->assertTrue($own->exists && $foreign->exists);
    }

    public function test_parent_list_excludes_other_children_documents()
    {
        Storage::fake('public');
        $this->makeDoc($this->foreignRecord()->id, 'Vault foreign parent probe', 'for-p1');
        $response = $this->actingAs($this->parent())->get(route('documents.index'));
        $response->assertOk();
        $response->assertDontSee('Vault foreign parent probe');
    }

    public function test_download_ownership()
    {
        Storage::fake('public');
        $own = $this->makeDoc($this->ownRecord()->id, 'Vault dl own', 'dl-own');
        $foreign = $this->makeDoc($this->foreignRecord()->id, 'Vault dl foreign', 'dl-for');
        $this->actingAs($this->student())->get(route('documents.download', $own->id))->assertOk();
        $this->actingAs($this->student())->get(route('documents.download', $foreign->id))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('documents.download', $own->id))->assertForbidden();
        // Demo parent owns the demo student's record, so a child download passes.
        $this->actingAs($this->parent())->get(route('documents.download', $own->id))->assertOk();
        $this->actingAs($this->parent())->get(route('documents.download', $foreign->id))->assertForbidden();
        $this->actingAs($this->admin())->get(route('documents.download', $foreign->id))->assertOk();
    }

    public function test_oversize_and_wrong_type_uploads_refused()
    {
        $response = $this->actingAs($this->admin())->post(route('documents.store'), [
            'student_record_id' => $this->ownRecord()->id,
            'title' => 'Vault oversize probe',
            'document' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
        ]);
        $response->assertSessionHasErrors('document');
        $this->assertNull(StudentDocument::where('title', 'Vault oversize probe')->first());

        $response = $this->actingAs($this->admin())->post(route('documents.store'), [
            'student_record_id' => $this->ownRecord()->id,
            'title' => 'Vault wrongtype probe',
            'document' => UploadedFile::fake()->createWithContent('evil.exe', "MZ vault wrongtype unique-bbb\n"),
        ]);
        $response->assertSessionHasErrors('document');
        $this->assertNull(StudentDocument::where('title', 'Vault wrongtype probe')->first());
    }

    public function test_admin_destroy_removes_row_and_file_and_non_admin_forbidden()
    {
        Storage::fake('public');
        $doc = $this->makeDoc($this->ownRecord()->id, 'Vault destroy probe', 'del-1');
        $path = $doc->stored_path;
        $this->actingAs($this->teacher())->delete(route('documents.destroy', $doc->id))->assertForbidden();
        $this->assertNotNull(StudentDocument::find($doc->id));
        $this->actingAs($this->admin())->delete(route('documents.destroy', $doc->id))
            ->assertRedirect(route('documents.index'));
        $this->assertNull(StudentDocument::find($doc->id));
        Storage::disk('public')->assertMissing($path);
    }
}
