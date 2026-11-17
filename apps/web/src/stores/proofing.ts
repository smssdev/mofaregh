import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { http } from '@/api/http'
import { compileRules } from '@/lib/proofing'
import { FORMULAS, setFormulas } from '@/lib/autocorrect'
import type { ProofRule } from '@/lib/proofing'

export interface ImportResult {
  created: number
  updated: number
  errors: { index: number; pattern: string; message: string }[]
}

/** The proofing dictionary (/api/proofing-rules), shared by the editor and the dictionary page. */
export const useProofingStore = defineStore('proofing', () => {
  const rules = ref<ProofRule[]>([])
  const canEdit = ref(false)
  const loaded = ref(false)
  let pending: Promise<void> | null = null

  /** Recompiled whenever a rule changes; the editor re-checks with the new one. */
  const checker = shallowRef(compileRules([]))
  /** Vowelled formulas (rules of type "formula"): auto-correct, typing and the ribbon use them. */
  const formulas = computed(() => (loaded.value ? rules.value.filter((r) => r.type === 'formula' && r.enabled).map((r) => r.pattern) : [...FORMULAS]))
  const recompile = () => {
    checker.value = compileRules(rules.value)
    setFormulas(formulas.value)
  }

  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    pending ??= http
      .get<{ data: ProofRule[]; can_edit: boolean }>('/proofing-rules')
      .then(({ data }) => {
        rules.value = data.data
        canEdit.value = data.can_edit
        loaded.value = true
        recompile()
      })
      .finally(() => (pending = null))
    return pending
  }

  async function save(rule: Partial<ProofRule> & { id?: number }): Promise<ProofRule> {
    const { data } = rule.id
      ? await http.put<{ data: ProofRule }>(`/proofing-rules/${rule.id}`, rule)
      : await http.post<{ data: ProofRule }>('/proofing-rules', rule)
    const saved = data.data
    const index = rules.value.findIndex((r) => r.id === saved.id)
    rules.value = index === -1 ? [...rules.value, saved] : rules.value.map((r) => (r.id === saved.id ? saved : r))
    recompile()
    return saved
  }

  async function remove(rule: ProofRule): Promise<void> {
    await http.delete(`/proofing-rules/${rule.id}`)
    rules.value = rules.value.filter((r) => r.id !== rule.id)
    recompile()
  }

  async function importRules(list: unknown[]): Promise<ImportResult> {
    const { data } = await http.post<ImportResult>('/proofing-rules/import', { rules: list })
    await load(true)
    return data
  }

  const counts = computed(() => {
    const byType: Record<string, number> = {}
    for (const r of rules.value) byType[r.type] = (byType[r.type] ?? 0) + 1
    return byType
  })

  return { rules, canEdit, loaded, checker, formulas, counts, load, save, remove, importRules }
})
