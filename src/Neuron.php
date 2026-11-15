<?php

declare(strict_types=1);

namespace NeuronAI\Laravel;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;

use function tap;

class Neuron
{
    /** @var array<ToolInterface|ToolkitInterface> */
    protected array $tools = [];

    /**
     * Pending node-middleware registrations, each a pair of
     * [node class(es), middleware instance(s)].
     *
     * @var array<int, array{string|array<string>, WorkflowMiddleware|array<WorkflowMiddleware>}>
     */
    protected array $middleware = [];

    /**
     * The conversation thread the next call is bound to. Null means a new,
     * anonymous conversation per call, with a generated thread ID.
     */
    protected ?string $threadId = null;

    protected ?MessageStoreInterface $messageStore = null;

    public function __construct(
        protected AIProviderInterface $provider,
        protected ?string $instructions = null,
    ) {}

    /**
     * @param ToolInterface|ToolkitInterface|array<ToolInterface|ToolkitInterface> $tools
     */
    public function tools(ToolInterface|ToolkitInterface|array $tools): static
    {
        return tap(clone $this, fn (self $clone) => $clone->tools = is_array($tools) ? $tools : [$tools]);
    }

    /**
     * @param class-string<NodeInterface>|array<class-string<NodeInterface>> $node
     * @param WorkflowMiddleware|array<WorkflowMiddleware> $middleware
     */
    public function middleware(string|array $node, WorkflowMiddleware|array $middleware): static
    {
        return tap(clone $this, fn (self $clone) => $clone->middleware[] = [$node, $middleware]);
    }

    /**
     * Bind the next call to a conversation thread. NeuronAI 4 addresses every
     * agent by a thread ID; pair it with messageStore() to keep the history
     * between calls.
     */
    public function for(string $threadId): static
    {
        return tap(clone $this, fn (self $clone) => $clone->threadId = $threadId);
    }

    /**
     * Store the conversation in $store. Without it each call uses a fresh
     * in-memory store, so nothing is remembered between calls.
     */
    public function messageStore(MessageStoreInterface $store): static
    {
        return tap(clone $this, fn (self $clone) => $clone->messageStore = $store);
    }

    private function makeAgent(): Agent
    {
        // NeuronAI 4 never generates a thread ID. Without one bound through
        // for(), each call is a new anonymous conversation, as in NeuronAI 3.
        $agent = Agent::make(workflowId: $this->threadId ?? UniqueIdGenerator::generateId('thread_'));
        if ($this->messageStore instanceof MessageStoreInterface) {
            $agent->setMessageStore($this->messageStore);
        }
        $agent->setAiProvider($this->provider);
        if ($this->instructions !== null) {
            $agent->setInstructions($this->instructions);
        }
        if ($this->tools !== []) {
            $agent->addTool($this->tools);
        }
        foreach ($this->middleware as [$node, $middleware]) {
            $agent->addMiddleware($node, $middleware);
        }
        return $agent;
    }

    public function chat(mixed $message): AgentState
    {
        return $this->makeAgent()->chat($message);
    }

    public function stream(mixed $message): Generator
    {
        return $this->makeAgent()->stream($message);
    }

    /**
     * @throws \Throwable
     * @throws AgentException
     */
    public function structured(mixed $message, string $outputClass): mixed
    {
        return $this->makeAgent()->structured($message, $outputClass);
    }
}
