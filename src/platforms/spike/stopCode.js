// Halts all six SPIKE 3 ports and unpairs any motor pairs. Each call is
// guarded: ports with no motor (or a sensor) raise, and unpair raises when the
// pair was never paired. Sent with board.paste (not eval) so the for-block's
// closing newline survives and the loop actually runs.
export const stopCode = `
import motor
import motor_pair
from hub import port

for _p in (port.A, port.B, port.C, port.D, port.E, port.F):
    try:
        motor.stop(_p)
    except Exception:
        pass

for _pair in (motor_pair.PAIR_1, motor_pair.PAIR_2, motor_pair.PAIR_3):
    try:
        motor_pair.unpair(_pair)
    except Exception:
        pass
`;
