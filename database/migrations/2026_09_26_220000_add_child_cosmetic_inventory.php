<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_profiles', function (Blueprint $table) {
            $table->json('purchased_items')->nullable();
            $table->json('equipped_accessories')->nullable();
        });

        foreach (DB::table('child_profiles')->get() as $child) {
            $owned = [];
            $equippedAccessories = [];

            if (in_array($child->equipped_costume, ['cap', 'glasses', 'scarf', 'crown'], true)) {
                $owned[] = 'accessory:' . $child->equipped_costume;
                $equippedAccessories[] = $child->equipped_costume;
            }
            if (in_array($child->equipped_sticker, ['rainbow', 'pizza', 'bolt', 'unicorn', 'ufo', 'icecream'], true)) {
                $owned[] = 'sticker:' . $child->equipped_sticker;
            }

            DB::table('child_profiles')->where('id', $child->id)->update([
                'purchased_items' => json_encode($owned),
                'equipped_accessories' => json_encode($equippedAccessories),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('child_profiles', function (Blueprint $table) {
            $table->dropColumn(['purchased_items', 'equipped_accessories']);
        });
    }
};
