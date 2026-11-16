// Deterministic tone for a client: every project of a client gets the same one of
// the six `.tone-N` colours (base.css), so a client is recognisable by colour.
// Pass the client id; a project id is only a fallback where no client is known.
export function glyphClass(clientId) {
  return `tone-${Math.abs(Number(clientId) || 0) % 6}`;
}
