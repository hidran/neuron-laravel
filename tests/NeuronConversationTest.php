<?php

declare(strict_types=1);

namespace NeuronAI\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Laravel\Facades\Neuron as NeuronFacade;
use NeuronAI\Laravel\Models\ChatMessage;
use NeuronAI\Laravel\Neuron;
use NeuronAI\Testing\FakeAIProvider;

use function array_map;

/**
 * The README's conversation example: the facade bound to a thread and
 * stored with the package's own chat_messages migration and model.
 */
class NeuronConversationTest extends BasicTestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function test_a_thread_stored_with_eloquent_survives_between_calls(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Nice to meet you, Valerio'),
            new AssistantMessage('Your name is Valerio'),
        );
        $this->app->instance(Neuron::class, new Neuron($provider));

        // Two separate facade calls, as two HTTP requests would make them.
        NeuronFacade::for('user-1')
            ->messageStore(new EloquentMessageStore(ChatMessage::class))
            ->chat(new UserMessage('My name is Valerio'));
        NeuronFacade::for('user-1')
            ->messageStore(new EloquentMessageStore(ChatMessage::class))
            ->chat(new UserMessage('What is my name?'));

        $this->assertSame(4, ChatMessage::query()->where('thread_id', 'user-1')->count());
        // Every row carries the identity EloquentMessageStore appends by.
        $this->assertSame(0, ChatMessage::query()->whereNull('message_id')->count());
        $this->assertSame(
            ['My name is Valerio', 'Nice to meet you, Valerio', 'What is my name?'],
            array_map(fn (Message $m): mixed => $m->getContent(), $provider->getRecorded()[1]->messages),
        );
    }

    public function test_appending_the_same_message_twice_stores_it_once(): void
    {
        $store = new EloquentMessageStore(ChatMessage::class);
        $message = new UserMessage('Only once');

        $store->append('user-1', $message);
        $store->append('user-1', $message);

        $this->assertSame(1, ChatMessage::query()->where('thread_id', 'user-1')->count());
        $this->assertSame($message->getId(), $store->loadActive('user-1')[0]->getId());
    }
}
