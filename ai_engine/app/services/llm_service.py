# app/services/llm_service.py
import asyncio

import httpx
import os
import json
import traceback
from app.schemas.chat import ChatRequest, ChatResponse, ModelTarget
from app.services.rag_service import RAGService

class LLMService:
    @staticmethod
    def _log_debug(title, data):
        print(f"\n{'='*20} DEBUG: {title} {'='*20}")
        if isinstance(data, (dict, list)):
            print(json.dumps(data, indent=2))
        else:
            print(data)
        print(f"{'='*50}\n")

    @staticmethod
    async def _build_context(request: ChatRequest):
        """Shared RAG lookup and system prompt building."""
        user_query = request.messages[-1].content
        context_str = ""
        sources = []

        try:
            query_emb = await RAGService.get_embedding(user_query)
            search_results = RAGService.search(query_emb, request.session_id)

            context_parts = []
            for chunk, meta, score in search_results:
                if score > 0.3:
                    context_parts.append(f"[File: {meta['source']}]\n{chunk}")
                    sources.append(meta['source'])

            if context_parts:
                context_str = "\n\n---\n\n".join(context_parts)
        except Exception as e:
            print(f"RAG Error (Non-Fatal): {e}")

        system_prompt = (
            f"You are ScholarAI. DOCUMENT CONTEXT:\n{context_str}" if context_str
            else "You are ScholarAI, an intelligent research assistant."
        )

        messages = [{"role": "system", "content": system_prompt}] + [m.dict() for m in request.messages]
        return messages, sources

    @staticmethod
    async def process_chat(request: ChatRequest) -> ChatResponse:
        print(f"--- Processing Chat for Provider: {request.provider} | Model: {request.model} ---")

        messages, sources = await LLMService._build_context(request)

        try:
            if request.provider.startswith("openrouter"):
                return await LLMService._call_openrouter(request, messages, sources)
            else:
                return await LLMService._call_ollama(request, messages, sources)
        except Exception as e:
            print(f"FATAL ERROR in {request.provider}: {str(e)}")
            traceback.print_exc()
            raise e

    @staticmethod
    async def process_chat_batch(request: "ChatRequest") -> dict:
        """
        Run all targets in parallel using asyncio.gather.
 
        FIXED: Each target now carries its own 'messages' (per-model history).
        RAG embedding is computed once on the *current user turn* and the
        retrieved context is injected into each model's individual system
        prompt — so context is shared but conversation history is isolated.
        """
        print(f"--- Batch Processing {len(request.targets)} models in parallel ---")
 
        # ── Step 1: Run RAG once for the current user query ───────────────────
        # We use the last message in the top-level messages list as the query.
        user_query = request.messages[-1].content if request.messages else ""
        context_str = ""
        sources: list[str] = []
 
        try:
            query_emb = await RAGService.get_embedding(user_query)
            search_results = RAGService.search(query_emb, request.session_id)
 
            context_parts = []
            for chunk, meta, score in search_results:
                if score > 0.3:
                    context_parts.append(f"[File: {meta['source']}]\n{chunk}")
                    sources.append(meta['source'])
 
            if context_parts:
                context_str = "\n\n---\n\n".join(context_parts)
        except Exception as e:
            print(f"RAG Error (Non-Fatal): {e}")
 
        # ── Step 2: Call each model with its own per-model history ────────────
        async def _call_single(target: "ModelTarget"):
            try:
                # ── Build this model's message list ───────────────────────────
                # Use the target's own messages (its isolated history).
                # Fall back to the top-level messages if the target has none
                # (e.g. first-ever message in the session).
                per_model_messages = target.messages if target.messages else request.messages
 
                # Inject RAG context as a system prompt prepended to the history
                if context_str:
                    system_msg = {"role": "system", "content": f"You are ScholarAI. DOCUMENT CONTEXT:\n{context_str}"}
                else:
                    system_msg = {"role": "system", "content": "You are ScholarAI, an intelligent research assistant."}
 
                messages_with_system = [system_msg] + [m.dict() for m in per_model_messages]
 
                # ── Build a single-model ChatRequest for the provider call ─────
                single = ChatRequest(
                    session_id=request.session_id,
                    user_id=request.user_id,
                    messages=per_model_messages,   # per-model history
                    model=target.model,
                    provider=target.provider,
                    context_data=request.context_data,
                    options=request.options,
                )
 
                if target.provider.startswith("openrouter"):
                    response = await LLMService._call_openrouter(single, messages_with_system, sources)
                else:
                    response = await LLMService._call_ollama(single, messages_with_system, sources)
 
                return target.model_id, response
 
            except Exception as e:
                print(f"Error for model {target.model}: {e}")
                import traceback
                traceback.print_exc()
                return target.model_id, ChatResponse(
                    content=f"Error: {str(e)}",
                    model=target.model,
                    metadata={"error": str(e)},
                )
 
        results_list = await asyncio.gather(*[_call_single(t) for t in request.targets])
        return {model_id: response for model_id, response in results_list}

    @staticmethod
    async def _call_openrouter(request: ChatRequest, messages: list, sources: list):
        # Map provider name to its corresponding API key env variable
        api_key_map = {
            "openrouter1": "OPENROUTER_API_KEY1",
            "openrouter2": "OPENROUTER_API_KEY2",
            "openrouter3": "OPENROUTER_API_KEY3",
        }
        api_key_var = api_key_map.get(request.provider, "OPENROUTER_API_KEY1")
        api_key = os.getenv(api_key_var)
        api_url = os.getenv("OPENROUTER_API_URL")

        if not api_key or not api_url:
            raise ValueError(f"{api_key_var} or OPENROUTER_API_URL not set")

        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "http://localhost:8000",
            "X-Title": "ScholarCompass"
        }

        clean_messages = []
        for m in messages:
            if not clean_messages or clean_messages[-1]['role'] != m['role']:
                clean_messages.append(m)
            else:
                clean_messages[-1]['content'] += f"\n{m['content']}"

        async with httpx.AsyncClient(timeout=300.0) as client:
            resp = await client.post(
                api_url,
                headers=headers,
                json={"model": request.model, "messages": clean_messages}
            )

            print(f"OpenRouter Status [{request.model}]: {resp.status_code}")
            data = resp.json()

            if resp.status_code != 200:
                raise Exception(f"OpenRouter API error {resp.status_code}: {data.get('error', data)}")

            if 'choices' not in data or not data['choices']:
                raise Exception(f"No choices in OpenRouter response: {data}")

            msg_obj = data['choices'][0]['message']
            content = msg_obj.get('content') or ''
            reasoning = msg_obj.get('reasoning') or ''

            full_content = f"<think>\n{reasoning}\n</think>\n{content}" if reasoning else content

            return ChatResponse(
                content=full_content,
                model=request.model,
                usage=data.get("usage", {}),
                metadata={
                    "sources": list(set(sources)),
                    "rag_applied": len(sources) > 0,
                    "raw_reasoning": reasoning
                }
            )

    @staticmethod
    async def _call_ollama(request: ChatRequest, messages: list, sources: list):
        async with httpx.AsyncClient(timeout=None) as client:
            resp = await client.post(
                "http://127.0.0.1:11434/api/chat",
                json={"model": request.model, "messages": messages, "stream": False}
            )
            data = resp.json()
            return ChatResponse(
                content=data['message']['content'],
                model=request.model,
                usage={"total_tokens": data.get("eval_count", 0)},
                metadata={"sources": list(set(sources)), "rag_applied": len(sources) > 0}
            )