<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskQuestionRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Chat\AnswerQuestion;
use App\Services\UsageLimits;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $request->user()->conversations()->latest('updated_at')->latest('id')->paginate(30)
        );
    }

    public function show(Request $request, int $conversation): ConversationResource
    {
        return new ConversationResource($this->find($request, $conversation)->load('messages'));
    }

    public function destroy(Request $request, int $conversation): Response
    {
        $this->find($request, $conversation)->delete();

        return response()->noContent();
    }

    public function ask(AskQuestionRequest $request, AnswerQuestion $answer, UsageLimits $limits): JsonResponse
    {
        $limits->consumeQuestion($request->user(), ClientIp::of($request));

        $conversation = $request->filled('conversation_id')
            ? $this->find($request, $request->integer('conversation_id'))
            : null;

        $message = $answer(
            $request->user(),
            $request->string('question')->trim()->value(),
            $conversation,
            $request->input('document_ids'),
        );

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    public function stream(AskQuestionRequest $request, AnswerQuestion $answer, UsageLimits $limits): StreamedResponse
    {
        $limits->consumeQuestion($request->user(), ClientIp::of($request));

        $conversation = $request->filled('conversation_id')
            ? $this->find($request, $request->integer('conversation_id'))
            : null;

        $user = $request->user();
        $question = $request->string('question')->trim()->value();
        $documentIds = $request->input('document_ids');

        return response()->eventStream(
            fn () => $answer->stream($user, $question, $conversation, $documentIds),
            endStreamWith: null,
        );
    }

    private function find(Request $request, int $id): Conversation
    {
        return $request->user()->conversations()->findOrFail($id);
    }
}
