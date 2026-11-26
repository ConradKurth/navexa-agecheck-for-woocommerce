import { gate } from "./gate"
import { gateOptionsFrom } from "./options"

/**
 * Starts the entry gate from a script tag that carries data-navexa-gate
 * (valueless, "" or any value: presence turns it on). The gate stands down
 * when one is already showing.
 */
export function autoGate(script: Element | null): void {
  if (script && script.hasAttribute("data-navexa-gate")) gate(gateOptionsFrom(script))
}
