<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('communication_messages',function(Blueprint $t){
   $t->string('id',40)->primary(); $t->string('kind',8); $t->string('thread_key',64)->index();
   $t->string('sender_id',190)->index(); $t->string('sender_name',150); $t->string('subject',250)->default('');
   $t->text('body'); $t->json('recipients'); $t->json('attachments'); $t->string('reply_to',40)->nullable(); $t->timestamps();
  });
  Schema::create('communication_folders',function(Blueprint $t){
   $t->string('id',40)->primary();$t->string('user_id',190)->index();$t->string('name',80);$t->timestamps();$t->unique(['user_id','name']);
  });
  Schema::create('communication_participants',function(Blueprint $t){
   $t->id();$t->string('message_id',40);$t->string('user_id',190);$t->boolean('is_sender')->default(false);
   $t->string('box',12);$t->string('folder_id',40)->nullable();$t->boolean('starred')->default(false);$t->timestamp('read_at')->nullable();
   $t->unique(['message_id','user_id']);$t->index(['user_id','box','folder_id']);
   $t->foreign('folder_id')->references('id')->on('communication_folders')->nullOnDelete();
   $t->foreign('message_id')->references('id')->on('communication_messages')->cascadeOnDelete();
  });
  Schema::create('communication_drafts',function(Blueprint $t){
   $t->string('id',40)->primary();$t->string('user_id',190)->index();$t->json('data');$t->timestamps();
  });
 }
 public function down(): void {foreach(['communication_drafts','communication_participants','communication_folders','communication_messages'] as $t)Schema::dropIfExists($t);}
};
