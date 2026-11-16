import { useRef, useEffect, useState } from 'react';
import './App.css';
import SPIKEEditor from './components/SPIKEEditor';
import ChatPanel from './components/ChatPanel';
import HardwarePickerModal from './components/HardwarePickerModal';
import TitleBar from './components/TitleBar';
import DebugManager from './components/DebugManager';
import { SessionProvider, useSession } from './contexts/SessionContext';
import { useLanguage } from './contexts/LanguageContext';
import instance from './config/instance';
import { getPlatform } from './platforms';
import { platformDisplayName } from './platforms/displayName';

// Sessions are an implementation detail: the app resumes the most recent
// session and "Switch hardware" resumes or creates one session per platform.

const sessionRecency = (s) => s.last_updated || s.start_time || '';

/** Most recently used session, optionally restricted to one platform. */
function latestSession(sessions, platformId = null) {
  return (sessions || [])
    .filter((s) =>
      platformId
        ? s.hardware_platform === platformId
        : s.hardware_platform && instance.platforms.includes(s.hardware_platform)
    )
    .reduce((best, s) => (!best || sessionRecency(s) > sessionRecency(best) ? s : best), null);
}

function AppContent() {
  const { t } = useLanguage();
  const {
    activeSession,
    sessions,
    loadSessions,
    setActiveSessionById,
    updateCurrentCodeContent,
    currentCodeContent,
    createSnapshot,
    availablePlatforms,
    createSessionWithPlatform,
    activePlatform,
  } = useSession();

  const [showDebugModal, setShowDebugModal] = useState(false);
  const [sessionsInitialized, setSessionsInitialized] = useState(false);
  const [hardwarePicker, setHardwarePicker] = useState({ visible: false, dismissable: true });

  const resizerRef = useRef(null);
  const containerRef = useRef(null);
  const spikeEditorRef = useRef(null);

  // Resume the most recent session, or ask for hardware on first visit.
  useEffect(() => {
    if (sessionsInitialized) return;
    loadSessions().then(async (userSessions) => {
      const latest = latestSession(userSessions);
      if (latest) {
        await setActiveSessionById(latest.id);
      } else {
        setHardwarePicker({ visible: true, dismissable: false });
      }
      setSessionsInitialized(true);
    });
  }, [sessionsInitialized, loadSessions, setActiveSessionById]);

  // Resizable pane logic
  useEffect(() => {
    const resizer = resizerRef.current;
    const container = containerRef.current;
    if (!resizer || !container) return;

    let isDragging = false;

    const handleMouseDown = (e) => {
      e.preventDefault();
      isDragging = true;
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';
    };

    const handleMouseMove = (e) => {
      if (!isDragging) return;

      const rect = container.getBoundingClientRect();
      const offsetX = e.clientX - rect.left;
      const percent = (offsetX / rect.width) * 100;

      // Constrain between 15% and 85%
      const constrainedPercent = Math.min(Math.max(percent, 15), 85);
      container.style.gridTemplateColumns = `${constrainedPercent}% 5px auto`;
    };

    const handleMouseUp = () => {
      if (isDragging) {
        isDragging = false;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
      }
    };

    resizer.addEventListener('mousedown', handleMouseDown);
    window.addEventListener('mousemove', handleMouseMove);
    window.addEventListener('mouseup', handleMouseUp);

    return () => {
      resizer.removeEventListener('mousedown', handleMouseDown);
      window.removeEventListener('mousemove', handleMouseMove);
      window.removeEventListener('mouseup', handleMouseUp);
    };
  }, [activeSession]);

  // Resume this platform's most recent session, else start one.
  const handleHardwarePick = async (platformId) => {
    const existing = latestSession(sessions, platformId);
    let ok = true;
    if (existing) {
      if (existing.id !== activeSession?.id) {
        ok = await setActiveSessionById(existing.id);
      }
    } else {
      const platform = getPlatform(platformId);
      ok = await createSessionWithPlatform({
        name: platformDisplayName(platform, t),
        platformId,
        initialCode: platform?.starterCode ?? t('initialCode'),
      });
    }
    if (ok !== false) {
      setHardwarePicker({ visible: false, dismissable: true });
    }
  };

  const handleReplaceCode = async (newCode) => {
    // Create snapshot for AI replacement with the new code
    await createSnapshot('ai_replace', newCode);

    // Update the current code in session context (local state)
    await updateCurrentCodeContent(newCode);
  };

  const getCodeContent = async () => {
    if (spikeEditorRef.current) {
      return spikeEditorRef.current.getCode();
    }
    return currentCodeContent;
  };

  const getConsoleContent = async () => {
    if (spikeEditorRef.current) {
      return spikeEditorRef.current.getBuffer();
    }
    return '';
  };

  return (
    <>
      <HardwarePickerModal
        visible={hardwarePicker.visible}
        dismissable={hardwarePicker.dismissable}
        platforms={availablePlatforms || []}
        activePlatformId={activeSession?.hardware_platform || null}
        onPick={handleHardwarePick}
        onClose={() => setHardwarePicker({ visible: false, dismissable: true })}
      />

      <div className="app-container">
        <TitleBar
          onShowDebug={() => setShowDebugModal(true)}
          hardwareLabel={platformDisplayName(activePlatform, t)}
          onSwitchHardware={() => setHardwarePicker({ visible: true, dismissable: true })}
        />
        <div className="main-content" ref={containerRef}>
          <div className="left-panel">
            <SPIKEEditor
              ref={spikeEditorRef}
              sessionId={activeSession?.id}
            />
          </div>
          <div className="horizontal-resizer" ref={resizerRef}></div>
          <div className="right-panel">
            <ChatPanel
              onReplaceCode={handleReplaceCode}
              getCodeContent={getCodeContent}
              getConsoleContent={getConsoleContent}
            />
          </div>
        </div>
      </div>

      <DebugManager
        visible={showDebugModal}
        onClose={() => setShowDebugModal(false)}
      />
    </>
  );
}

function App() {
  return (
    <SessionProvider>
      <AppContent />
    </SessionProvider>
  );
}

export default App;
