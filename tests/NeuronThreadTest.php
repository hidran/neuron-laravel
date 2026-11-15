<?php

declare(strict_types=1);

namespace NeuronAI\Laravel\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Laravel\Facades\Neuron as NeuronFacade;
use NeuronAI\Laravel\Neuron;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Testing\FakeAIProvider;
use ReflectionClass;

use function array_map;

/**
 * Structured output target used only by these tests.
 */
class Greeting
{
    #[SchemaProperty(description: 'The name of the person greeted.', required: true)]
    public string $name;
}

/**
 * NeuronAI 4 never generates a thread ID: every Agent must be bound to one
 * before chat(), stream() or structured() run. These tests drive the three
 * interaction modes end to end with a fake provider.
 */
class NeuronThreadTest extends BasicTestCase
{
    private function makeAgent(Neuron $neuron): Agent
    {
        return (new ReflectionClass($neuron))->getMethod('makeAgent')->invoke($neuron);
    }

    public function test_chat_runs_without_an_explicit_thread(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hi there'));

        $state = (new Neuron($provider))->chat(new UserMessage('Hello'));

        $this->assertInstanceOf(AgentState::class, $state);
        $this->assertSame('Hi there', $state->getMessage()?->getContent());
    }

    public function test_stream_runs_without_an_explicit_thread(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Streamed answer'));

        $text = '';
        $stream = (new Neuron($provider))->stream(new UserMessage('Hello'));
        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $text .= $chunk->content;
            }
        }

        $this->assertSame('Streamed answer', $text);
        $this->assertInstanceOf(AgentState::class, $stream->getReturn());
    }

    public function test_structured_runs_without_an_explicit_thread(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "John"}'));

        $greeting = (new Neuron($provider))->structured(new UserMessage('I am John'), Greeting::class);

        $this->assertInstanceOf(Greeting::class, $greeting);
        $this->assertSame('John', $greeting->name);
    }

    public function test_every_call_gets_its_own_generated_thread(): void
    {
        $neuron = new Neuron(new FakeAIProvider());

        $first = $this->makeAgent($neuron)->getThreadId();
        $second = $this->makeAgent($neuron)->getThreadId();

        $this->assertNotNull($first);
        $this->assertStringStartsWith('thread_', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_for_returns_a_bound_clone_and_leaves_the_original_untouched(): void
    {
        $neuron = new Neuron(new FakeAIProvider());

        $bound = $neuron->for('conversation-42');

        $this->assertNotSame($neuron, $bound);
        $this->assertSame('conversation-42', $this->makeAgent($bound)->getThreadId());
        $this->assertNotSame('conversation-42', $this->makeAgent($neuron)->getThreadId());
    }

    public function test_a_bound_thread_with_a_shared_store_continues_the_conversation(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Nice to meet you, Valerio'),
            new AssistantMessage('Your name is Valerio'),
        );
        $neuron = (new Neuron($provider))
            ->for('conversation-42')
            ->messageStore(new InMemoryMessageStore());

        $neuron->chat(new UserMessage('My name is Valerio'));
        $neuron->chat(new UserMessage('What is my name?'));

        $secondRequest = $provider->getRecorded()[1]->messages;
        $this->assertSame(
            ['My name is Valerio', 'Nice to meet you, Valerio', 'What is my name?'],
            array_map(fn (Message $m): mixed => $m->getContent(), $secondRequest),
        );
    }

    public function test_message_store_returns_a_clone_and_leaves_the_original_untouched(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('one'), new AssistantMessage('two'));
        $store = new InMemoryMessageStore();
        $neuron = (new Neuron($provider))->for('conversation-42');

        $neuron->messageStore($store)->chat(new UserMessage('stored'));
        $neuron->chat(new UserMessage('not stored'));

        // The original had no store: its call started from an empty history.
        $this->assertSame(
            ['not stored'],
            array_map(fn (Message $m): mixed => $m->getContent(), $provider->getRecorded()[1]->messages),
        );
    }

    public function test_the_facade_chat_works_on_the_container_singleton(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('From the facade'));
        $this->app->instance(Neuron::class, new Neuron($provider));

        $state = NeuronFacade::chat(new UserMessage('Hello'));

        $this->assertSame('From the facade', $state->getMessage()?->getContent());
    }
}
