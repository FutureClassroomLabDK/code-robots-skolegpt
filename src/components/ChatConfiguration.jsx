/**
 * Chat Configuration Component
 * Coding-level selector and the clear-chat button
 */

import { useLanguage } from '../contexts/LanguageContext';
import './ChatConfiguration.css';

const ChatConfiguration = ({
  codingLevel,
  onCodingLevelChange,
  onClearChat,
  clearChatDisabled = false,
}) => {
  const { t } = useLanguage();

  return (
    <div className="chat-configuration">
      <div className="config-left">
        <label htmlFor="coding-level-selector">{t('codingLevel')}</label>
        <select
          id="coding-level-selector"
          className="chat-level-selector"
          value={codingLevel}
          onChange={(e) => onCodingLevelChange(e.target.value)}
        >
          <option value="beginner">{t('beginner')}</option>
          <option value="intermediate">{t('intermediate')}</option>
          <option value="experienced">{t('experienced')}</option>
        </select>
      </div>
      {onClearChat && (
        <button
          type="button"
          className="clear-history-btn"
          onClick={onClearChat}
          disabled={clearChatDisabled}
          title={t('clearChatTitle')}
        >
          {t('clearChat')}
        </button>
      )}
    </div>
  );
};

export default ChatConfiguration;

