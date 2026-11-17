/**
 * Chat Panel Component
 * Handles AI chat with streaming, markdown rendering, and code snippets
 */

import { useState, useRef, useEffect } from 'react';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import { useSession } from '../contexts/SessionContext';
import { useLanguage } from '../contexts/LanguageContext';
import { logMessage, logConsole } from '../services/dataLogger';
import { streamTutorCompletion } from '../utils/chatStream';
import CodeModal from './CodeModal';
import ConsoleModal from './ConsoleModal';
import ChatConfiguration from './ChatConfiguration';
import './ChatPanel.css';

// Configure marked for better markdown rendering
marked.setOptions({
  breaks: true,
  gfm: true,
});

// Logged as ai_model on assistant messages.
const TUTOR_AI_MODEL = 'tutor-pipeline';

const ChatPanel = ({ onReplaceCode, getCodeContent, getConsoleContent }) => {
  const { 
    activeSession, 
    conversationHistory,
    currentConversationId,
    createNewConversation,
    createSnapshot,
    activePlatform,
  } = useSession();
  const { t, lang } = useLanguage();

  const [messages, setMessages] = useState([]);
  const [inputText, setInputText] = useState('');
  const [codingLevel, setCodingLevel] = useState('beginner');
  const [isStreaming, setIsStreaming] = useState(false);
  const [streamingConversationId, setStreamingConversationId] = useState(null);
  const [attachedContext, setAttachedContext] = useState({ includeCode: false, includeConsole: false });
  const [codeModalOpen, setCodeModalOpen] = useState(false);
  const [currentCodeSnippet, setCurrentCodeSnippet] = useState({ code: '', lang: '' });
  const [consoleModalOpen, setConsoleModalOpen] = useState(false);
  const [currentConsoleContent, setCurrentConsoleContent] = useState('');
  const [consoleHasContent, setConsoleHasContent] = useState(false);

  const chatBodyRef = useRef(null);
  const streamingMessageRef = useRef(null);
  const stickToBottomRef = useRef(true);
  const currentConversationIdRef = useRef(currentConversationId);
  const warnedNoTutorHwModeRef = useRef(false);

  // Startup sanity check: the tutor needs a platform with a tutorHwMode.
  useEffect(() => {
    if (warnedNoTutorHwModeRef.current) return;
    if (activePlatform && !activePlatform.tutorHwMode) {
      warnedNoTutorHwModeRef.current = true;
      console.warn(
        `Tutor chat: active platform "${activePlatform.id}" has no tutorHwMode; tutor requests will be rejected.`
      );
    }
  }, [activePlatform]);

  useEffect(() => {
    currentConversationIdRef.current = currentConversationId;
  }, [currentConversationId]);

  // Load conversation history from session
  useEffect(() => {
    if (conversationHistory && conversationHistory.length > 0) {
      // Filter out system messages for display
      const displayMessages = conversationHistory
        .filter(msg => msg.role !== 'system')
        .map(msg => ({
          role: msg.role === 'user' ? 'user' : 'bot',
          content: msg.content,
          messageId: msg.id,
        }));
      setMessages(displayMessages);
      
    } else {
      setMessages([]);
    }
  }, [conversationHistory]);

  // Scroll to bottom when messages change, but only if the user is already near the bottom
  useEffect(() => {
    if (stickToBottomRef.current) {
      scrollToBottom();
    }
  }, [messages]);

  // Track whether the user is scrolled near the bottom so we know whether to auto-scroll
  const handleChatScroll = () => {
    const el = chatBodyRef.current;
    if (!el) return;
    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
    stickToBottomRef.current = distanceFromBottom < 50;
  };

  // Check console content availability on mount / when the accessor changes
  useEffect(() => {
    const checkConsole = async () => {
      if (getConsoleContent) {
        const content = await getConsoleContent();
        setConsoleHasContent(content && content.trim().length > 0);
      }
    };
    checkConsole();
  }, [getConsoleContent]);

  // React immediately to console buffer changes from the editor (device
  // connect/disconnect, output, clear) so the "Add Console to Chat" button
  // enables/disables without needing a tab switch to force a re-render.
  useEffect(() => {
    const handler = (e) => {
      setConsoleHasContent(!!e.detail?.hasContent);
    };
    window.addEventListener('console-content-changed', handler);
    return () => window.removeEventListener('console-content-changed', handler);
  }, []);

  const scrollToBottom = () => {
    if (chatBodyRef.current) {
      requestAnimationFrame(() => {
        chatBodyRef.current.scrollTop = chatBodyRef.current.scrollHeight;
      });
    }
  };

  const handleSendMessage = async () => {
    if (!activeSession) {
      alert(t('noActiveSession'));
      return;
    }
    if (!activePlatform?.tutorHwMode) {
      // Platform has no tutor pipeline support — surface an error in chat
      // instead of calling the endpoint.
      setMessages(prev => [...prev, { role: 'bot', content: t('tutorPlatformUnsupported') }]);
      return;
    }

    let text = inputText.trim();
    if (!text && !attachedContext.includeCode && !attachedContext.includeConsole) {
      return;
    }

    // Fetch context at send time
    let codeContextId = null;
    const finalContext = { code: null, console: null };
    if (attachedContext.includeCode && getCodeContent) {
      finalContext.code = await getCodeContent();
      // Snapshot the code as it was at send time and link the message to that
      // immutable snapshot (messages.code_context_id -> code_snapshots.id).
      // Referencing the mutable code record would make every message point at
      // the same row whose content keeps changing.
      const snapshotId = await createSnapshot('chat_context');
      codeContextId = typeof snapshotId === 'number' && snapshotId > 0 ? snapshotId : null;
    }
    if (attachedContext.includeConsole && getConsoleContent) {
      finalContext.console = await getConsoleContent();
    }

    // Raw user text before code/console wrapping — the tutor pipeline stacks
    // code/console itself, so user_msg must stay unwrapped.
    const rawText = text;

    // Build display message with wrapped code and console
    if (finalContext.code) {
      text += '\n```python\n' + finalContext.code + '\n```';
    }
    if (finalContext.console) {
      text += '\n````\n' + finalContext.console + '\n````';
    }

    // Add user message to UI — always scroll to bottom on send
    stickToBottomRef.current = true;
    if (text.trim()) {
      setMessages(prev => [...prev, { role: 'user', content: text }]);
    }

    setInputText('');
    setAttachedContext({ includeCode: false, includeConsole: false });

    // Log context to database
    const sessionId = activeSession.id;
    const conversationId = currentConversationId;
    let consoleContextId = null;

    if (finalContext.console && finalContext.console.trim()) {
      consoleContextId = await logConsole(finalContext.console, sessionId, 'chat_context');
    }

    // Log user message
    if (text) {
      await logMessage({
        conversation_id: conversationId,
        role: 'user',
        content: text,
        coding_level: codingLevel,
        code_context_id: codeContextId,
        console_context_id: consoleContextId,
      });
    }

    // The server-side tutor pipeline owns prompt assembly. Send raw fields
    // (history/user_msg/hw_mode/level/lang + optional code/console) and
    // render progress events as a thinking trace on the bot message.
    // history = prior turns only (messages state before this send; the
    // current user message is carried separately in user_msg).
    const history = messages.map(msg => ({
      role: msg.role === 'user' ? 'user' : 'assistant',
      content: msg.content,
    }));

    const payload = {
      history,
      user_msg: rawText || 'Please analyze the provided context.',
      hw_mode: activePlatform.tutorHwMode,
      level: codingLevel,
      lang,
    };
    if (finalContext.code && finalContext.code.trim()) {
      payload.code = finalContext.code;
    }
    if (finalContext.console && finalContext.console.trim()) {
      payload.console = finalContext.console;
    }

    setIsStreaming(true);
    setStreamingConversationId(conversationId);
    streamingMessageRef.current = '';

    // Patch the streaming bot message in place; skipped when the user has
    // started a different conversation meanwhile.
    const updateLast = (patch) => {
      if (currentConversationIdRef.current !== conversationId) return;
      setMessages(prev => {
        if (prev.length === 0) return prev;
        const next = [...prev];
        next[next.length - 1] = { ...next[next.length - 1], ...patch };
        return next;
      });
    };

    try {
      let fullResponse = '';
      const thinking = [];

      // Insert a placeholder bot message immediately so the thinking trace
      // is visible while progress events arrive (before the first token).
      setMessages(prev => [...prev, { role: 'bot', content: '', thinking: [], streaming: true }]);

      for await (const event of streamTutorCompletion(payload)) {
        if (event.type === 'progress') {
          thinking.push({ stage: event.stage, status: event.status, payload: event.payload || {} });
          updateLast({ thinking: [...thinking] });
        } else if (event.type === 'reasoning') {
          // Model "thinking" stream: grow the current turn's step, or start one.
          const last = thinking[thinking.length - 1];
          if (last && last.stage === 'reasoning' && last.payload.turn === event.turn) {
            thinking[thinking.length - 1] = {
              ...last,
              payload: { ...last.payload, text: last.payload.text + (event.delta || '') },
            };
          } else {
            thinking.push({ stage: 'reasoning', status: 'running', payload: { turn: event.turn, text: event.delta || '' } });
          }
          updateLast({ thinking: [...thinking] });
        } else if (event.type === 'content') {
          fullResponse += event.content;
          streamingMessageRef.current = fullResponse;
          updateLast({ content: fullResponse });
        }
      }

      // Finalize message
      updateLast({ content: fullResponse, thinking: [...thinking], streaming: false });

      // Log assistant message
      await logMessage({
        conversation_id: conversationId,
        role: 'assistant',
        content: fullResponse,
        coding_level: codingLevel,
        ai_model: TUTOR_AI_MODEL,
        prompt_tokens: 0,
        completion_tokens: 0,
      });
    } catch (error) {
      console.error('Streaming error:', error);
      updateLast({ content: `${t('errorPrefix')}${error.message}`, streaming: false });
    } finally {
      setIsStreaming(false);
      setStreamingConversationId(null);
      streamingMessageRef.current = null;
    }
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') {
      if (e.shiftKey) {
        // Shift+Enter: allow default (newline)
      } else {
        // Enter: send message
        e.preventDefault();
        handleSendMessage();
      }
    }
  };

  const openCodeModal = (code, lang) => {
    setCurrentCodeSnippet({ code, lang });
    setCodeModalOpen(true);
  };

  const closeCodeModal = () => {
    setCodeModalOpen(false);
    setCurrentCodeSnippet({ code: '', lang: '' });
  };

  const handleCopyCode = () => {
    navigator.clipboard.writeText(currentCodeSnippet.code);
    closeCodeModal();
  };

  const handleReplaceCode = () => {
    if (onReplaceCode) {
      onReplaceCode(currentCodeSnippet.code);
    }
    closeCodeModal();
  };

  const openConsoleModal = (consoleContent) => {
    setCurrentConsoleContent(consoleContent);
    setConsoleModalOpen(true);
  };

  const closeConsoleModal = () => {
    setConsoleModalOpen(false);
    setCurrentConsoleContent('');
  };

  const handleCopyConsole = () => {
    navigator.clipboard.writeText(currentConsoleContent);
    closeConsoleModal();
  };

  // Collapsible tutor-pipeline thinking trace (progress events). Open while
  // streaming, collapsed once the answer is complete.
  const renderThinkingTrace = (thinking, streaming) => {
    if (!thinking || thinking.length === 0) return null;
    const labels = {
      summarize: t('thinkingSummarize'),
      doc_routing: t('thinkingDocRouting'),
      outline: t('thinkingOutline'),
    };
    return (
      <details className="chat-thinking" open={streaming}>
        <summary>
          {streaming ? t('thinkingInProgress') : t('thinkingDone')} ({thinking.length} {t('thinkingSteps')})
        </summary>
        <ul className="chat-thinking-steps">
          {thinking.map((step, idx) => {
            let label = labels[step.stage] || step.stage;
            const p = step.payload || {};
            let detail = null;
            if (step.stage === 'summarize') {
              detail = p.summarized
                ? t('thinkingSummarized')
                    .replace('{before}', p.before_tokens)
                    .replace('{after}', p.after_tokens)
                : t('thinkingNotSummarized');
            } else if (step.stage === 'doc_routing' && Array.isArray(p.bundles)) {
              detail = p.bundles.length
                ? t('thinkingDocsLabel').replace('{bundles}', p.bundles.join(', '))
                : t('thinkingDocsNone');
            } else if (step.stage === 'outline' && p.outline) {
              detail = p.outline;
            } else if (step.stage === 'reasoning') {
              label = t('thinkingReasoning').replace('{turn}', p.turn);
              detail = p.text;
            } else if (step.stage === 'tool_call') {
              // Agent loop: the model asked to run its code in the simulator.
              label = t('thinkingToolCall')
                .replace('{run}', p.run)
                .replace('{max}', p.max_runs)
                .replace('{scenario}', p.scenario || '');
              detail = (
                <>
                  {p.note && <div>{p.note}</div>}
                  {p.code && (
                    <details>
                      <summary>{t('thinkingToolCode')}</summary>
                      {p.code}
                    </details>
                  )}
                </>
              );
            } else if (step.stage === 'simulate') {
              label = t('thinkingSimulate').replace('{run}', p.run).replace('{max}', p.max_runs);
              const scenario = p.scenario || '';
              detail = (
                <>
                  <div>
                    {p.error
                      ? t('thinkingSimError').replace('{scenario}', scenario).replace('{error}', p.error)
                      : t('thinkingSimOk').replace('{scenario}', scenario)}
                  </div>
                  {p.trace && (
                    <details>
                      <summary>{t('thinkingSimTrace')}</summary>
                      {p.trace}
                    </details>
                  )}
                </>
              );
            }
            return (
              <li key={idx}>
                <strong>{label}</strong>
                {detail && (
                  <div
                    className="chat-thinking-detail"
                    // Keep live reasoning scrolled to the newest text.
                    ref={streaming && step.stage === 'reasoning'
                      ? (el) => { if (el) el.scrollTop = el.scrollHeight; }
                      : undefined}
                  >
                    {detail}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      </details>
    );
  };

  const renderMessage = (message, index) => {
    const isUser = message.role === 'user';
    const label = isUser ? t('userLabel') : message.role === 'system' ? 'System' : t('botLabel');
    const color = isUser ? '#fbe2d7' : message.role === 'system' ? '#d7e4fb' : '#d8f6d8';
    const align = isUser ? 'align-right' : 'align-left';

    // Use messageId from database for consistent keys, fallback to index for display
    const messageKey = message.messageId || index;

    // First split by console blocks (4 backticks)
    const consoleSegments = message.content.split(/````([\s\S]*?)````/g);

    return (
      <div key={index} className={`chat-msg-wrap ${align}`}>
        <div className="chat-label">{label}</div>
        <div className="chat-bubble" style={{ backgroundColor: color }}>
          {!isUser && renderThinkingTrace(message.thinking, message.streaming)}
          {consoleSegments.map((consoleSeg, consoleIdx) => {
            if (consoleIdx % 2 === 1) {
              // This is a console block
              const consoleText = consoleSeg.trim();
              return (
                <button
                  key={consoleIdx}
                  className="console-btn"
                  onClick={() => openConsoleModal(consoleText)}
                >
                  {t('viewConsoleLog')}
                </button>
              );
            } else {
              // Not a console block, check for code blocks (3 backticks)
              const codeSegments = consoleSeg.split(/```([\s\S]*?)```/g);
              
              return codeSegments.map((codeSeg, codeIdx) => {
                if (codeIdx % 2 === 0) {
                  // Markdown text
                  if (codeSeg.trim()) {
                    const html = DOMPurify.sanitize(marked.parse(codeSeg, { async: false }));
                    return (
                      <div 
                        key={`${consoleIdx}-${codeIdx}`} 
                        className="markdown-content"
                        dangerouslySetInnerHTML={{ __html: html }} 
                      />
                    );
                  }
                } else {
                  // Code block
                  let codeText = codeSeg;
                  let lang = '';
                  const firstNL = codeSeg.indexOf('\n');
                  if (firstNL !== -1) {
                    const firstLine = codeSeg.slice(0, firstNL).trim();
                    if (/^[a-zA-Z0-9+#-]+$/.test(firstLine)) {
                      lang = firstLine;
                      codeText = codeSeg.slice(firstNL + 1);
                    }
                  }

                  // Create unique key for this code snippet using messageId from database
                  const codeKey = `msg-${messageKey}-${consoleIdx}-${codeIdx}`;
                  
                  // Check if this is Python code from a bot message
                  const isPython = lang === 'python' || lang === 'py';
                  const isBot = !isUser && message.role !== 'system';
                  
                  return (
                    <div key={`${consoleIdx}-${codeIdx}`} style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                      <button
                        className="code-btn"
                        onClick={() => openCodeModal(codeText, lang)}
                      >
                        {t('viewCodeSnippet')}
                      </button>
                    </div>
                  );
                }
                return null;
              });
            }
          })}
        </div>
      </div>
    );
  };

  return (
    <div className="chat-panel">
      <ChatConfiguration
        codingLevel={codingLevel}
        onCodingLevelChange={setCodingLevel}
        onClearChat={activeSession ? createNewConversation : undefined}
        clearChatDisabled={isStreaming}
      />

      <div className="chat-body" ref={chatBodyRef} onScroll={handleChatScroll}>
        <div className="chat-disclaimer">
          {t('chatDisclaimer')}
        </div>
        {messages.map((msg, idx) => renderMessage(msg, idx))}
        {isStreaming && streamingConversationId === currentConversationId && (
          <div className="chat-spinner" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
            <div className="loader"></div>
          </div>
        )}
      </div>

      <div className="chat-input-area">
        <div className="chat-context-controls">
          <button
            type="button"
            className={`context-checkbox-btn ${attachedContext.includeCode ? 'context-checkbox-btn--active' : ''}`}
            onClick={() => setAttachedContext(prev => ({ ...prev, includeCode: !prev.includeCode }))}
          >
            <span className="context-checkbox-btn__box">{attachedContext.includeCode ? '✓' : ''}</span>
            <span className="context-checkbox-btn__label">{t('addCodeToChat')}</span>
          </button>
          <button
            type="button"
            className={`context-checkbox-btn ${attachedContext.includeConsole ? 'context-checkbox-btn--active' : ''}`}
            onClick={() => setAttachedContext(prev => ({ ...prev, includeConsole: !prev.includeConsole }))}
            disabled={!consoleHasContent}
          >
            <span className="context-checkbox-btn__box">{attachedContext.includeConsole ? '✓' : ''}</span>
            <span className="context-checkbox-btn__label">{t('addConsoleToChat')}</span>
          </button>
        </div>

        <div className="chat-input-row">
          <textarea
            id="chat-input"
            rows="2"
            placeholder={t('messagePlaceholder')}
            value={inputText}
            onChange={(e) => setInputText(e.target.value)}
            onKeyDown={handleKeyDown}
            disabled={isStreaming}
          />
          <button id="chat-send" onClick={handleSendMessage} disabled={isStreaming}>
            {t('send')}
          </button>
        </div>
      </div>

      {/* Code Modal */}
      <CodeModal
        isOpen={codeModalOpen}
        code={currentCodeSnippet.code}
        lang={currentCodeSnippet.lang}
        onClose={closeCodeModal}
        onCopy={handleCopyCode}
        onReplace={handleReplaceCode}
      />

      {/* Console Modal */}
      <ConsoleModal
        isOpen={consoleModalOpen}
        consoleContent={currentConsoleContent}
        onClose={closeConsoleModal}
        onCopy={handleCopyConsole}
      />

    </div>
  );
};

export default ChatPanel;

