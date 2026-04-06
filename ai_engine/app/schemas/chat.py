from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any

class ChatMessage(BaseModel):
    """Represents a single exchange in the conversation history."""
    role: str # e.g., "user", "assistant", "system"
    content: str

class ModelTarget(BaseModel):
    model: str
    provider: str
    model_id: str  # PHP's UUID for this model
    messages: Optional[List[ChatMessage]] = Field(default_factory=list)

class ChatRequest(BaseModel):
    session_id: str
    user_id: str
    messages: List[ChatMessage]
    model: str
    provider: str    
    # context_data allows PHP to send extra info (like user's research field) 
    # to help Python's Advanced RAG logic retrieve better documents.
    context_data: Optional[Dict[str, Any]] = Field(default_factory=dict)
    # options holds LLM parameters like temperature, top_p, or max_tokens.
    options: Optional[Dict[str, Any]] = Field(default_factory=dict)
    # Optional batch: if provided, run all models in parallel
    targets: Optional[List[ModelTarget]] = Field(default=None)

class ChatResponse(BaseModel):
    """
    Standardized response returned to PHP.
    Maps easily back to the OpenAI format.
    """
    content: str
    model: str
    usage: Dict[str, Any] = Field(
        default_factory=lambda: {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0}
    )
    
    # metadata can store 'thinking traces', source citations (for RAG), 
    # or generation time.
    metadata: Dict[str, Any] = Field(default_factory=dict)
    
class BatchChatResponse(BaseModel):
    results: Dict[str, ChatResponse]  # keyed by model_id (PHP UUID)