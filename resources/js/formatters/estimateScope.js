// Form state for an estimate's scope: an ordered list of sections, each with
// its own lines. A plain estimate is edited as one section with no label or
// title; the PDF prints such a section without a heading or subtotal.
import { lineAmountRappen } from '@/formatters/vat.js';

let nextKey = 0;
const key = () => nextKey++;

export function makeLine(rate = 0, values = {}) {
  return { key: key(), title: '', description: '', hours: 0, rate, ...values };
}

export function makeSection(rate = 0, values = {}) {
  return { key: key(), label: '', title: '', lines: [makeLine(rate)], ...values };
}

/** Form sections from server data ({label,title,lines:[{title,description,hours,rate}]}). */
export function seedSections(sections, rate = 0) {
  if (!sections?.length) return [makeSection(rate)];
  return sections.map((s) => makeSection(rate, {
    label: s.label ?? '',
    title: s.title ?? '',
    lines: (s.lines ?? []).map((l) => makeLine(rate, {
      title: l.title ?? '',
      description: l.description ?? '',
      hours: l.hours,
      rate: l.rate,
    })),
  }));
}

export function flattenLines(sections) {
  return sections.flatMap((s) => s.lines);
}

export function sectionTotals(section) {
  return {
    hours: section.lines.reduce((sum, l) => sum + Number(l.hours || 0), 0),
    amount: section.lines.reduce((sum, l) => sum + lineAmountRappen(l), 0),
  };
}

/** Request payload for `sections` (rates in rappen, blank fields as null). */
export function sectionsPayload(sections) {
  return sections
    .filter((s) => s.lines.length > 0)
    .map((s) => ({
      label: s.label.trim() || null,
      title: s.title.trim() || null,
      lines: s.lines.map((l) => ({
        title: (l.title ?? '').trim() || null,
        description: (l.description ?? '').trim() || null,
        hours: Number(l.hours),
        rate_rappen: Math.round(Number(l.rate) * 100),
      })),
    }));
}

export function assumptionsPayload(assumptions) {
  return assumptions.map((a) => a.text.trim()).filter(Boolean);
}

export function seedAssumptions(list) {
  return (list ?? []).map((text) => ({ key: key(), text }));
}

export function makeAssumption() {
  return { key: key(), text: '' };
}
