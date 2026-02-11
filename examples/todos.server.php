<?php

use App\Models\Todo;
use Illuminate\Http\Request;
use InertiaKit\Prop;
use InertiaKit\ServerPage;

return ServerPage::make('Todos/Index')
    ->middleware('auth')
    ->loader(fn (): array => [
        'todos' => Todo::all(),
        'completedCount' => Prop::defer(fn () => Todo::where('completed', true)->count()),
        'tags' => Prop::merge(fn () => Todo::pluck('tag')->unique()->values()),
    ])
    ->post('addTodo', function (Request $request) {
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
    ->put('updateTodo', function (Todo $todo, Request $request) {
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
    ->delete('deleteTodo', function (Todo $todo) {
        $todo->delete();

        return [
            'deleted' => true,
        ];
    });
