// Deterministic project/client badge color from an immutable id.
// Maps to one of the five `.proj-glyph` color classes (alt-0 = base accent).
export function glyphClass(id) {
  return `alt-${Math.abs(Number(id)) % 5}`;
}
