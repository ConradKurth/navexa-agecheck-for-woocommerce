/**
 * The gate-only script build, agecheck-gate-<version>.js (B3): small and
 * safe to load synchronously in <head>. window.NavexaAgeCheck = {version,
 * gate, isRemembered}, and the entry gate from this script tag's data-
 * attributes when it carries data-navexa-gate.
 */
import { autoGate } from "./autogate"
import { gate, isRemembered } from "./gate"
import { publish } from "./global"
import { VERSION } from "./version"

publish({ version: VERSION, gate, isRemembered })
autoGate(document.currentScript)
