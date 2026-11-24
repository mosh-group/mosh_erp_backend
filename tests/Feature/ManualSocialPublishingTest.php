<?php
namespace Tests\Feature;

use App\Models\{Customer, StoredFile, User};
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManualSocialPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_destinations_are_recorded_independently_and_retries_are_safe(): void
    {
        $this->seed();
        $this->postJson('/api/auth/setup', [
            'name' => 'Test Admin', 'username' => 'admin',
            'password' => 'Test-Password123!', 'password_confirmation' => 'Test-Password123!',
        ])->assertCreated();
        Customer::create(['id'=>'CLIENT1','version'=>1,'data'=>['name'=>'Customer one']]);
        Customer::create(['id'=>'CLIENT2','version'=>1,'data'=>['name'=>'Customer two']]);
        Storage::fake('local')->put('manual/post.png', 'Fixture media');
        StoredFile::create(['id'=>'manual-asset','owner_id'=>User::firstOrFail()->id,'logical_key'=>'manual-asset',
            'name'=>'post.png','mime'=>'image/png','kind'=>'social-media','size'=>13,'path'=>'manual/post.png']);
        $base = ['attachmentKey'=>'manual-asset','customerId'=>'CLIENT1','caption'=>'Test post','platform'=>'instagram','requestId'=>'manual-instagram-test'];
        $id = $this->postJson('/api/social/manual/posts', $base)->assertCreated()->assertJsonPath('data.status','pending_manual')->json('data.id');
        $this->postJson('/api/social/manual/posts', $base)->assertCreated()->assertJsonPath('data.id',$id);
        $this->postJson('/api/social/manual/posts', array_replace($base, ['platform'=>'facebook','requestId'=>'manual-facebook-test']))
            ->assertCreated()->assertJsonPath('data.status','pending_manual');
        $this->getJson('/api/social/manual/posts?customerId=CLIENT2')->assertOk()->assertJsonCount(0,'data.posts');
        $this->getJson('/api/social/manual/posts?customerId=CLIENT1')->assertOk()->assertJsonCount(2,'data.posts');

        $this->getJson('/api/files/manual-asset')->assertOk();
        $path = '/api/social/manual/posts/'.$id.'/published';
        $finish = ['_version'=>1,'requestId'=>'confirm-manual-post','url'=>'https://www.instagram.com/p/Fixture'];
        $this->postJson($path, array_replace($finish, ['url'=>'https://example.com/fake']))->assertUnprocessable();
        $this->postJson($path, $finish)->assertOk()->assertJsonPath('data.status','manual_published')->assertJsonPath('data.canMark',false);
        $this->postJson($path, $finish)->assertOk()->assertJsonPath('data._version',2);

        $actor = User::firstOrFail();
        $data = $actor->data;
        $data['moduleOverrides']['social']['view'] = false;
        $actor->update(['is_root'=>false,'data'=>$data]);
        $this->actingAs($actor->fresh())->withSession(['auth_version'=>$actor->auth_version,'last_activity'=>time()]);
        $this->getJson('/api/social/manual/posts?customerId=CLIENT1')->assertForbidden();
        $this->getJson('/api/files/manual-asset')->assertNotFound();
    }
}
