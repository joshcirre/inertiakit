<?php

use InertiaKit\ServerPage;
use App\Models\Todo;
use Illuminate\Http\Request;

return ServerPage::make('Todos/Index')
    ->middleware('auth')
    ->loader(fn(): array => [
        'todos' => Todo::all(),
    ])
    ->action('addTodo', function (Request $request) {
        $todo = Todo::create(
            $request->validate([
                'title' => 'required|string',
                'completed' => 'boolean',
            ])
        );

        return [
            'todo' => $todo,
        ];
    })
    ->action('updateTodo', function (Todo $todo, Request $request) {
        $todo->update(
            $request->validate([
                'title' => 'string',
                'completed' => 'boolean',
            ])
        );

        return [
            'todo' => $todo,
        ];
    })
    ->action('deleteTodo', function (Todo $todo) {
        $todo->delete();

        return [
            'deleted' => true,
        ];
    });