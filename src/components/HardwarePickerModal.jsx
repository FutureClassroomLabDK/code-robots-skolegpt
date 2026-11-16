/**
 * Hardware Picker Modal
 * Large-tile platform picker. Picking a tile hands the platform id back;
 * App decides whether that resumes or creates a session.
 */

import { useEffect } from 'react';
import { useLanguage } from '../contexts/LanguageContext';
import { platformDisplayName } from '../platforms/displayName';
import './ModalBase.css';
import './HardwarePickerModal.css';

const HardwarePickerModal = ({
  visible,
  platforms = [],
  activePlatformId = null,
  onPick,
  onClose,
  dismissable = true,
}) => {
  const { t } = useLanguage();

  useEffect(() => {
    if (!visible || !dismissable) return;
    const handleKey = (e) => {
      if (e.key === 'Escape') onClose?.();
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [visible, dismissable, onClose]);

  if (!visible) return null;

  return (
    <div
      className="modal-overlay hw-picker-overlay"
      onClick={() => {
        if (dismissable) onClose?.();
      }}
    >
      <div className="modal-content hw-picker-content" onClick={(e) => e.stopPropagation()}>
        <h1 className="hw-picker-title">{t('hardwarePickerTitle')}</h1>
        <p className="hw-picker-subtitle">{t('hardwarePickerSubtitle')}</p>

        <div className="hw-picker-tiles">
          {platforms.map((platform) => (
            <button
              key={platform.id}
              type="button"
              className={`hw-picker-tile${activePlatformId === platform.id ? ' hw-picker-tile--active' : ''}`}
              onClick={() => onPick?.(platform.id)}
            >
              <span className="hw-picker-tile-label">{platformDisplayName(platform, t)}</span>
            </button>
          ))}
        </div>

        {dismissable && (
          <button type="button" className="hw-picker-close-button" onClick={onClose}>
            {t('close')}
          </button>
        )}
      </div>
    </div>
  );
};

export default HardwarePickerModal;
