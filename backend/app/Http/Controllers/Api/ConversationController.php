<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskQuestionRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Chat\AnswerQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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

    public function ask(AskQuestionRequest $request, AnswerQuestion $answer): JsonResponse
    {
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

    private function find(Request $request, int $id): Conversation
    {
        return $request->user()->conversations()->findOrFail($id);
    }
}
