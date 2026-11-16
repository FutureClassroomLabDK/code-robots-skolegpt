import { stopCode } from './stopCode';

/**
 * LEGO SPIKE Prime hub running SPIKE 3 firmware. The hub exposes a standard
 * MicroPython REPL over USB serial (115200 baud), so it reuses the generic
 * WebSerial Board path (connectionType 'spike' → SPIKEEditor.connectSerialRepl).
 * The tutor routes to the `spike` prompt bundle.
 */
const spikePlatform = {
  id: 'spike',
  label: 'LEGO SPIKE Prime',
  connectionType: 'spike',
  stopCode,
  tutorHwMode: 'spike',
};

export default spikePlatform;
