/**
 * TitleBar Component
 * "Code <hardware> med SkoleGPT" title, hardware switch, about, debug and
 * language toggle.
 */

import { useState } from 'react';
import AboutModal from './AboutModal';
import instance from '../config/instance';
import { useLanguage } from '../contexts/LanguageContext';
import './ModalBase.css';
import './TitleBar.css';

const brand = instance.brand;

/**
 * "Code <Robots> med SkoleGPT" — the middle word swaps to the active
 * hardware's name (and back to "Robots" on hover).
 */
const SwapTitle = ({ hardwareLabel, t }) => {
  const [hovered, setHovered] = useState(false);
  const showHardware = Boolean(hardwareLabel) && !hovered;
  return (
    <div className="topbar-title topbar-title--simple">
      {t('appTitlePrefix')}{' '}
      <span
        className={`topbar-title-swap${showHardware ? ' topbar-title-swap--hw' : ''}`}
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
      >
        <span className="topbar-title-swap-layer topbar-title-swap-layer--robots" aria-hidden={showHardware}>
          {t('appTitleRobots')}
        </span>
        {hardwareLabel && (
          <span className="topbar-title-swap-layer topbar-title-swap-layer--hw" aria-hidden={!showHardware}>
            {hardwareLabel}
          </span>
        )}
      </span>{' '}
      {t('appTitleSuffix')}
    </div>
  );
};

const TitleBar = ({
  onShowDebug,
  hardwareLabel = null,
  onSwitchHardware,
}) => {
  const { lang, switchLang, t } = useLanguage();
  const [showAbout, setShowAbout] = useState(false);

  return (
    <>
      <div className="topbar">
        {brand.logoSrc && (
          <div className="topbar-logo">
            <img
              src={brand.logoSrc}
              alt={brand.logoAlt}
              style={{ height: brand.logoHeight }}
            />
          </div>
        )}
        <SwapTitle hardwareLabel={hardwareLabel} t={t} />
        <div className="topbar-actions">
          <button
            className="topbar-button"
            onClick={() => setShowAbout(true)}
            title={t('aboutTitle')}
          >
            {t('about')}
          </button>
          {onShowDebug && (
            <button
              className="topbar-button"
              onClick={onShowDebug}
              title={t('debugTitle')}
            >
              {t('debug')}
            </button>
          )}
          {onSwitchHardware && (
            <button
              className="topbar-button"
              onClick={onSwitchHardware}
            >
              {t('switchHardware')}
            </button>
          )}
          {instance.locales.available.length > 1 && (
            <div className="topbar-lang-toggle">
              {instance.locales.available.map((code) => (
                <button
                  key={code}
                  className={`topbar-lang-btn${lang === code ? ' topbar-lang-btn--active' : ''}`}
                  onClick={() => switchLang(code)}
                >
                  {code.toUpperCase()}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>

      <AboutModal visible={showAbout} onClose={() => setShowAbout(false)} />
    </>
  );
};

export default TitleBar;
