<?php

use App\Models\User;
use Illuminate\Http\Request;
use InertiaKit\Prop;
use InertiaKit\ServerPage;

return ServerPage::make('Users/Edit')
    ->middleware('auth')
    ->loader(fn (User $user): array => [
        'user' => $user,
        'roles' => Prop::optional(fn () => $user->roles),
    ])
    ->types([
        'user' => User::class,
    ])
    ->put('updateProfile', function (User $user, Request $request) {
        $user->update(
            $request->validate([
                'name' => 'required|string',
                'email' => 'required|email',
            ])
        );

        return ['user' => $user->fresh()];
    });
