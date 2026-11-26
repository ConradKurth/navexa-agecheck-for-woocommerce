/**
 * The full script build, agecheck-<version>.js: window.NavexaAgeCheck =
 * {version, verify, gate, isRemembered}, and the entry gate from this script
 * tag's data- attributes when it carries data-navexa-gate (README).
 */
import { autoGate } from "./autogate"
import { gate, isRemembered } from "./gate"
import { publish } from "./global"
import { verify } from "./verify"
import { VERSION } from "./version"

publish({ version: VERSION, verify, gate, isRemembered })
autoGate(document.currentScript)
