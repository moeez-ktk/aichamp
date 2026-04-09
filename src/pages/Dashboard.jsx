import React, { useState, useEffect, useRef } from "react";
import "../styles/Dashboard.css";
import { chatService } from "../services/chat/ChatService";
import { sessionService } from "../services/chat/session/SessionService";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { Prism as SyntaxHighlighter } from "react-syntax-highlighter";
import { duotoneDark } from "react-syntax-highlighter/dist/esm/styles/prism";
import { useContext } from "react";
import { AuthContext } from "../guards/context/AuthContext";
const Dashboard = ({
  sessionData,
  sessionMessages,
  sessionModels,
  onSessionChange,
  onSessionCreated,
}) => {
  const [prompt, setPrompt] = useState("");
  const [error, setError] = useState("");
  const [models, setModels] = useState([]);
  const [messages, setMessages] = useState({});
  const [loadingModels, setLoadingModels] = useState({});
  const [loading, setLoading] = useState(false);
  const [loadingSession, setLoadingSession] = useState(false);

  // File upload states
  const [selectedFile, setSelectedFile] = useState(null);
  const [showFileMenu, setShowFileMenu] = useState(false);
  const fileInputRef = useRef(null);
  const fileMenuRef = useRef(null);

  const sessionId = sessionData?.id || null;
  const bottomRefs = useRef({});
  const { token } = useContext(AuthContext);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    const load = async () => {
      if (sessionData) {
        if (sessionModels?.length > 0) {
          if (sessionMessages?.length > 0) {
            const mappedModels = sessionModels.map((m) => ({
              id: m.model_id,
              name: m.model_name_full,
              visible: Number(m.is_visible),
            }));

            const msgMap = {};
            const visibleModelIds = mappedModels.filter(m => m.visible === 1).map(m => m.id);
            mappedModels.forEach((m) => {
              msgMap[m.id] = [];
              bottomRefs.current[m.id] = bottomRefs.current[m.id] || React.createRef();
            });

            // Build a map of prompts by id, preserving file_name
            const promptMap = {};
            sessionMessages?.forEach((msg) => {
               if (msg.type === "prompt") {
                 promptMap[msg.id] = msg;
               }
             });

            sessionMessages?.forEach((msg) => {
               // Only show messages for visible models
               if (msg.type === "response" && msgMap[msg.model_id] && visibleModelIds.includes(msg.model_id)) {
                 const prompt = promptMap[msg.prompt_id];
                 if (prompt && !msgMap[msg.model_id].some(item => item.type === "prompt" && item.content === prompt.content)) {
                   // Include file_name from the stored prompt if it exists
                   msgMap[msg.model_id].push({
                     type: "prompt",
                     content: prompt.content,
                     file: prompt.file_name ? { name: prompt.file_name } : null,
                   });
                 }
                 msgMap[msg.model_id].push({
                   type: "response",
                   content: msg.content,
                 });
               }
             });

            setModels(mappedModels);
            setMessages(msgMap);
          }
          setLoading(false);
          setLoadingSession(false);
          return;
        } else {
          setModels([]);
          setMessages({});
          setLoadingSession(true);
          setLoading(false);
          return;
        }
      }

      if (!sessionData) {
        const res = await chatService.getModels();
        if (!res.ok) return;

        const mappedModels = res.data.data.map((m) => ({
          id: m.id,
          name: m.name,
          visible: 1,
        }));

        const msgMap = {};
        mappedModels.forEach((m) => {
          msgMap[m.id] = [];
          bottomRefs.current[m.id] = React.createRef();
        });

        setModels(mappedModels);
        setMessages(msgMap);
      }
    };

    load();
  }, [sessionData, sessionModels, sessionMessages]);

  useEffect(() => {
    const restoreSession = async () => {
      if (sessionData) return;

      const storedSessionId = localStorage.getItem("currentSessionId");
      if (!storedSessionId) return;

      try {
        const sessionRes = await sessionService.getSessionById(storedSessionId);
        const modelRes = await sessionService.getSessionModels(storedSessionId);
        const msgRes = await sessionService.getSessionMessages(storedSessionId);

        if (!sessionRes.ok || !modelRes.ok) return;

        onSessionChange(
          sessionRes.data.data.session,
          msgRes?.data?.data?.messages || [],
          modelRes.data.data.models
        );
      } catch (err) {
        console.error("Failed to restore session", err);
      }
    };

    restoreSession();
  }, []);

  useEffect(() => {
    models.forEach((model) => {
      bottomRefs.current[model.id]?.current?.scrollIntoView({
        behavior: "smooth",
      });
    });
  }, [messages, loadingModels, models]);

  // Handle clicks outside file menu to close it
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (fileMenuRef.current && !fileMenuRef.current.contains(event.target)) {
        setShowFileMenu(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
      // Validate file type
      const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
      const allowedExtensions = ['.pdf', '.doc', '.docx'];
      const fileExtension = file.name.toLowerCase().substring(file.name.lastIndexOf('.'));
      
      if (!allowedTypes.includes(file.type) && !allowedExtensions.includes(fileExtension)) {
        setError("Please select a PDF or Word file (.pdf, .doc, .docx)");
        return;
      }
      
      setSelectedFile(file);
      setError("");
      setShowFileMenu(false);
    }
  };

  const handleRemoveFile = () => {
    setSelectedFile(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  };

  const handleFileMenuClick = () => {
    setShowFileMenu(!showFileMenu);
  };

  const openFileDialog = () => {
    if (fileInputRef.current) {
      fileInputRef.current.click();
    }
  };

  const handleToggle = async (modelId, newState) => {
    try {
      const res = await sessionService.updateModelVisibility(
        sessionId,
        modelId,
        { is_visible: newState }
      );
      if (!res.ok) return;

      setModels((prev) =>
        prev.map((m) =>
          m.id === modelId ? { ...m, visible: newState } : m
        )
      );
    } catch (err) {
      console.error(err);
    }
  };

  const generateTitle = (text) =>
    text.trim().split(/\s+/).slice(0, 3).join(" ");

  const isSending = Object.values(loadingModels).some(Boolean);

  const handleSubmit = async () => {
    if (!prompt.trim() || isSending) return;
    setError("");

    const loaders = {};
    models.forEach((m) => {
      if (m.visible === 1) loaders[m.id] = true;
    });
    setLoadingModels(loaders);

    const visibleModelsList = models.filter(m => m.visible === 1);
    const visibleModelIds = visibleModelsList.map(m => m.id);

    const newMessages = { ...messages };
    visibleModelIds.forEach((id) => {
      if (newMessages[id]) {
        newMessages[id].push({ 
          type: "prompt", 
          content: prompt,
          file: selectedFile ? { name: selectedFile.name } : null
        });
      }
    });
    setMessages({ ...newMessages });

    try {
      let activeSessionId = sessionData?.id || null;

      if (!activeSessionId) {
        const createRes = await sessionService.createSession(
          generateTitle(prompt)
        );
        if (!createRes.ok) throw new Error("Create failed");

        const newSession = createRes.data.data.session;
        activeSessionId = newSession.id;

        localStorage.setItem("currentSessionId", activeSessionId);

        const modelsRes = await chatService.getModels();
        if (modelsRes.ok) {
          for (const m of modelsRes.data.data) {
            await sessionService.assignModelToSession(activeSessionId, m.id);
            await sessionService.updateModelVisibility(activeSessionId, m.id, {
              is_visible: 1,
            });
          }
        }

        const modelRes = await sessionService.getSessionModels(activeSessionId);
        if (modelRes.ok) {
          onSessionChange(newSession, [], modelRes.data.data.models);
          onSessionCreated(newSession);

          const mappedModels = modelRes.data.data.models.map((m) => ({
            id: m.model_id,
            name: m.model_name_full,
            visible: Number(m.is_visible),
          }));

          setModels(mappedModels);

          const loaders = {};
          mappedModels.forEach((m) => {
            if (m.visible === 1) loaders[m.id] = true;
          });
          setLoadingModels(loaders);

          await sessionService.activateSession(activeSessionId);

          const visibleModels = mappedModels.filter(m => m.visible === 1);
          const visibleModelIds = visibleModels.map(m => m.id);

          if (selectedFile) {
            // File uploads must go one at a time (multipart limitation)
            for (const model of visibleModels) {
              const res = await chatService.sendPromptWithFile(activeSessionId, model.id, prompt, selectedFile);
              if (res.ok) {
                newMessages[model.id].push({ type: "response", content: res.data?.data?.response?.content || "" });
              }
              setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
              setMessages((prev) => ({ ...prev, [model.id]: [...newMessages[model.id]] }));
            }
          } else {
            const res = await chatService.sendPromptBatch(activeSessionId, visibleModelIds, prompt);
            if (res.ok) {
              const responses = res.data?.data?.responses || {};
              for (const model of visibleModels) {
                const r = responses[model.id];
                if (r) {
                  newMessages[model.id].push({ type: "response", content: r.content || "" });
                }
                setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
              }
              setMessages({ ...newMessages });
            } else {
              for (const model of visibleModels) {
                setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
              }
            }
          }

          setPrompt("");
          handleRemoveFile();
          return;
                  }
      }

      await sessionService.activateSession(activeSessionId);

      const visibleModels = models.filter(m => m.visible === 1);
      const visibleModelIds = visibleModels.map(m => m.id);

      if (selectedFile) {
        for (const model of visibleModels) {
          const res = await chatService.sendPromptWithFile(activeSessionId, model.id, prompt, selectedFile);
          if (res.ok) {
            newMessages[model.id].push({ type: "response", content: res.data?.data?.response?.content || "" });
          }
          setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
          setMessages((prev) => ({ ...prev, [model.id]: [...newMessages[model.id]] }));
        }
      } else {
        const res = await chatService.sendPromptBatch(activeSessionId, visibleModelIds, prompt);
        if (res.ok) {
          const responses = res.data?.data?.responses || {};
          for (const model of visibleModels) {
            const r = responses[model.id];
            if (r) {
              newMessages[model.id].push({ type: "response", content: r.content || "" });
            }
            setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
          }
          setMessages({ ...newMessages });
        } else {
          for (const model of visibleModels) {
            setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
          }
        }
      }

      setPrompt("");
      handleRemoveFile();
    } catch (err) {
      console.error(err);
      setError("Error sending prompt");
      setLoadingModels({});
    }
  };

  if (loading || loadingSession) return (
    <div style={{ display: "flex", justifyContent: "center", alignItems: "center", height: "100vh" }}>
      <div className="dot-loader">
        <span></span>
        <span></span>
        <span></span>
      </div>
    </div>
  );

  return (
    <main className="dashboard">
      <div className="models-row">
        {models.map((model) => (
          <div className="model-card" key={model.id}>
            <div className="model-card-header">
              <span className="model-title">{model.name}</span>

              <label
                className={`switch ${
                  (!sessionId || models.length === 1)
                    ? "switch-disabled"
                    : ""
                }`}
              >
                <input
                  type="checkbox"
                  checked={model.visible === 1}
                  onChange={(e) =>
                    handleToggle(model.id, e.target.checked ? 1 : 0)
                  }
                  disabled={!sessionId || models.length === 1}
                />
                <span className="slider round"></span>
              </label>
            </div>

            <div className="model-card-content">
              <div className="chat-window">
                {(messages[model.id] || []).map((msg, idx) => (
                  <div
                    key={idx}
                    className={`chat-bubble ${
                      msg.type === "prompt" ? "msg-user" : "msg-ai"
                    }`}
                  >
                    {msg.type === "prompt" && msg.file && (
                      <div className="message-file-attachment">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                          <polyline points="14 2 14 8 20 8"></polyline>
                        </svg>
                        <span>{msg.file.name}</span>
                      </div>
                    )}
                    <ReactMarkdown
                      remarkPlugins={[remarkGfm]}
                      components={{
                        code({ inline, className, children }) {
                          const match =
                            /language-(\w+)/.exec(className || "");
                          return !inline && match ? (
                            <SyntaxHighlighter
                              style={duotoneDark}
                              language={match[1]}
                            >
                              {String(children)}
                            </SyntaxHighlighter>
                          ) : (
                            <code className="inline-code">{children}</code>
                          );
                        },
                      }}
                    >
                      {msg.content}
                    </ReactMarkdown>
                  </div>
                ))}

                {loadingModels[model.id] && (
                  <div className="chat-bubble msg-ai typing">
                    <div className="dot-loader">
                      <span></span>
                      <span></span>
                      <span></span>
                    </div>
                  </div>
                )}

                <div ref={bottomRefs.current[model.id]} />
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="prompt-box">
        <div className="prompt-inner">
          {/* Hidden file input */}
          <input
            type="file"
            ref={fileInputRef}
            onChange={handleFileSelect}
            accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            style={{ display: 'none' }}
          />
          
          {/* Left side: Add file button */}
          <div className="prompt-left-actions" ref={fileMenuRef}>
            <button
              type="button"
              className="add-file-btn"
              onClick={handleFileMenuClick}
              title="Add file"
            >
              +
            </button>
            
            {/* File menu dropdown */}
            {showFileMenu && (
              <div className="file-menu">
                <button 
                  type="button" 
                  className="file-menu-item"
                  onClick={openFileDialog}
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                  </svg>
                  Add Photo & Files
                </button>
              </div>
            )}
          </div>

          {/* File Preview and Text Input */}
          <div className="prompt-content-wrapper">
            {/* File Preview */}
            {selectedFile && (
              <div className="file-preview">
                <div className="file-preview-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                  </svg>
                </div>
                <span className="file-preview-name">{selectedFile.name}</span>
                <button 
                  type="button" 
                  className="file-preview-remove" 
                  onClick={handleRemoveFile}
                >
                  ×
                </button>
              </div>
            )}

            <textarea
              className="prompt-input"
              placeholder="Ask anything…"
              value={prompt}
              disabled={isSending}
              rows={1}
              onChange={(e) => setPrompt(e.target.value)}
              onKeyDown={(e) => {
                if (isSending) {
                  e.preventDefault();
                  return;
                }
                if (e.key === "Enter" && !e.shiftKey) {
                  e.preventDefault();
                  handleSubmit();
                }
              }}
            />
          </div>

          <button
            className="submit-btn"
            onClick={handleSubmit}
            disabled={!prompt.trim() || isSending}
          >
            ➤
          </button>
        </div>
      </div>
    </main>
  );
};

export default Dashboard;
