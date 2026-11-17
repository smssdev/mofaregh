<script setup lang="ts">
/**
 * Transcription player: speed without pitch change, short/long skips, automatic rewind on resume, A-B loop,
 * volume boost and a speech filter through Web Audio, pause-while-typing, and a remembered position per file.
 * It sits in the editor's side panel; the toolbar above the text repeats its main controls through the methods
 * and state exposed below. It has no keyboard handling of its own: the editor page maps shortcuts to it.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { formatClock, parseClock } from '@/lib/shortcuts'

const props = defineProps<{
  src: string
  fileId: number
  /** Duration known to the server; streamed WebM may not report one until it is fully buffered. */
  durationSeconds: number | null
}>()

const emit = defineEmits<{ time: [ms: number] }>()

const SETTINGS_KEY = 'mofaregh.player'
const QUICK_RATES = [0.5, 0.75, 1, 1.25, 1.5, 2]
const MIN_RATE = 0.25
const MAX_RATE = 3
const MAX_VOLUME = 3

const settings = reactive({
  rate: 1,
  volume: 1,
  skip: 5,
  rewindOnResume: 1.5,
  pauseWhileTyping: false,
  enhance: false,
  preservePitch: true,
})

try {
  const saved = JSON.parse(localStorage.getItem(SETTINGS_KEY) ?? '{}') as Partial<typeof settings>
  for (const key of Object.keys(settings) as (keyof typeof settings)[]) {
    if (typeof saved[key] === typeof settings[key]) (settings as Record<string, unknown>)[key] = saved[key]
  }
} catch {
  // corrupt settings: keep the defaults
}
settings.rate = clamp(settings.rate, MIN_RATE, MAX_RATE)
settings.volume = clamp(settings.volume, 0, MAX_VOLUME)

const audio = ref<HTMLAudioElement | null>(null)
const bar = ref<HTMLElement | null>(null)
const playing = ref(false)
const current = ref(0)
const mediaDuration = ref(0)
const buffered = ref(0)
const loading = ref(false)
const failed = ref(false)
const loopA = ref<number | null>(null)
const loopB = ref<number | null>(null)
const settingsOpen = ref(false)
const jumping = ref(false)
const jumpText = ref('')
const jumpInput = ref<HTMLInputElement | null>(null)
const hover = ref<{ x: number; time: number } | null>(null)

const duration = computed(() => (Number.isFinite(mediaDuration.value) && mediaDuration.value > 0 ? mediaDuration.value : (props.durationSeconds ?? 0)))
const progress = computed(() => (duration.value > 0 ? Math.min(100, (current.value / duration.value) * 100) : 0))
const loopLabel = computed(() => (loopA.value === null ? 'تكرار مقطع' : loopB.value === null ? 'حدِّد النهاية' : 'إلغاء التكرار'))

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

// --- Web Audio: boost above 100% and the speech filter -------------------------------------------------------
let context: AudioContext | null = null
let source: MediaElementAudioSourceNode | null = null
let gain: GainNode | null = null
let highpass: BiquadFilterNode | null = null
let compressor: DynamicsCompressorNode | null = null

function ensureGraph(): void {
  if (context || !audio.value) return
  try {
    context = new AudioContext()
    source = context.createMediaElementSource(audio.value)
    gain = context.createGain()
    highpass = context.createBiquadFilter()
    highpass.type = 'highpass'
    highpass.frequency.value = 90 // hum and rumble below the voice
    compressor = context.createDynamicsCompressor()
    compressor.threshold.value = -30 // lifts quiet speech, tames loud peaks
    compressor.ratio.value = 4
    compressor.attack.value = 0.005
    compressor.release.value = 0.2
    wire()
  } catch {
    context = null // no Web Audio: plain playback still works, without boost or filter
  }
}

function wire(): void {
  if (!context || !source || !gain || !highpass || !compressor) return
  source.disconnect()
  highpass.disconnect()
  compressor.disconnect()
  gain.disconnect()
  if (settings.enhance) source.connect(highpass).connect(compressor).connect(gain)
  else source.connect(gain)
  gain.connect(context.destination)
  applyVolume()
}

function applyVolume(): void {
  if (!audio.value) return
  audio.value.volume = Math.min(1, settings.volume)
  if (gain) gain.gain.value = Math.max(1, settings.volume)
}

function applyRate(): void {
  if (!audio.value) return
  audio.value.playbackRate = settings.rate
  audio.value.preservesPitch = settings.preservePitch
}

// --- transport ---------------------------------------------------------------------------------------------
async function play(rewind = true): Promise<void> {
  const el = audio.value
  if (!el) return
  ensureGraph()
  if (context?.state === 'suspended') await context.resume()
  if (rewind && settings.rewindOnResume > 0 && el.currentTime > 0) {
    el.currentTime = Math.max(loopA.value ?? 0, el.currentTime - settings.rewindOnResume)
  }
  applyRate()
  try {
    await el.play()
  } catch {
    // interrupted by a pause or blocked by the browser: the button simply stays on "play"
  }
}

function pause(): void {
  audio.value?.pause()
}

function toggle(): void {
  if (playing.value) pause()
  else void play()
}

function seekTo(seconds: number): void {
  const el = audio.value
  if (!el) return
  const target = clamp(seconds, 0, duration.value || seconds)
  el.currentTime = target
  current.value = target
}

function seekBy(seconds: number): void {
  seekTo((audio.value?.currentTime ?? 0) + seconds)
}

function setRate(rate: number): void {
  settings.rate = clamp(Math.round(rate * 100) / 100, MIN_RATE, MAX_RATE)
}

function changeRate(delta: number): void {
  setRate(settings.rate + delta)
}

function changeVolume(delta: number): void {
  settings.volume = clamp(Math.round((settings.volume + delta) * 100) / 100, 0, MAX_VOLUME)
}

/** First call marks the start, second the end (and starts looping), third clears. */
function cycleLoop(): void {
  const now = audio.value?.currentTime ?? 0
  if (loopA.value === null) loopA.value = now
  else if (loopB.value === null) {
    if (now > loopA.value + 0.3) loopB.value = now
    else loopA.value = null
  } else {
    loopA.value = null
    loopB.value = null
  }
}

/** The editor calls this on every keystroke: with "pause while typing" it holds playback until typing stops. */
let typingTimer: number | undefined
let pausedForTyping = false
function typing(): void {
  if (!settings.pauseWhileTyping) return
  if (playing.value) {
    pausedForTyping = true
    pause()
  }
  if (!pausedForTyping) return
  window.clearTimeout(typingTimer)
  typingTimer = window.setTimeout(() => {
    pausedForTyping = false
    void play()
  }, 900)
}

defineExpose({
  toggle,
  play,
  pause,
  seekTo,
  seekBy,
  setRate,
  changeRate,
  changeVolume,
  cycleLoop,
  typing,
  currentTime: () => audio.value?.currentTime ?? 0,
  skip: () => settings.skip,
  // Read by the editor's toolbar, which repeats the main controls above the text.
  playing,
  current,
  duration,
  rate: computed(() => settings.rate),
})

// --- element events ----------------------------------------------------------------------------------------
const positionKey = computed(() => `mofaregh.position.${props.fileId}`)
let lastSaved = 0

function onTime(): void {
  const el = audio.value
  if (!el) return
  if (loopA.value !== null && loopB.value !== null && el.currentTime >= loopB.value) el.currentTime = loopA.value
  current.value = el.currentTime
  emit('time', Math.round(el.currentTime * 1000))
  if (Math.abs(el.currentTime - lastSaved) > 5) rememberPosition()
}

function rememberPosition(): void {
  lastSaved = audio.value?.currentTime ?? 0
  try {
    localStorage.setItem(positionKey.value, String(lastSaved))
  } catch {
    // storage full or disabled: the position is simply not remembered
  }
}

function onLoaded(): void {
  const el = audio.value
  if (!el) return
  mediaDuration.value = el.duration
  applyRate()
  applyVolume()
  const saved = Number(localStorage.getItem(positionKey.value))
  if (Number.isFinite(saved) && saved > 1 && saved < (duration.value || Infinity) - 2) seekTo(saved)
}

function onProgress(): void {
  const el = audio.value
  if (!el || el.buffered.length === 0 || duration.value <= 0) return
  buffered.value = Math.min(100, (el.buffered.end(el.buffered.length - 1) / duration.value) * 100)
}

// --- seek bar ----------------------------------------------------------------------------------------------
function timeAt(clientX: number): number {
  const rect = bar.value?.getBoundingClientRect()
  if (!rect || rect.width === 0) return 0
  return clamp((clientX - rect.left) / rect.width, 0, 1) * duration.value
}

function onBarDown(event: PointerEvent): void {
  if (duration.value <= 0) return
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  seekTo(timeAt(event.clientX))
}

function onBarMove(event: PointerEvent): void {
  const rect = bar.value?.getBoundingClientRect()
  if (!rect || duration.value <= 0) return
  hover.value = { x: clamp(event.clientX - rect.left, 0, rect.width), time: timeAt(event.clientX) }
  if (event.buttons === 1) seekTo(timeAt(event.clientX))
}

function onBarKey(event: KeyboardEvent): void {
  if (event.key === 'ArrowRight') seekBy(settings.skip)
  else if (event.key === 'ArrowLeft') seekBy(-settings.skip)
  else if (event.key === 'Home') seekTo(0)
  else if (event.key === 'End') seekTo(duration.value)
  else return
  event.preventDefault()
}

function startJump(): void {
  jumpText.value = formatClock(current.value)
  jumping.value = true
  void nextTick(() => jumpInput.value?.select())
}

function finishJump(): void {
  const seconds = parseClock(jumpText.value)
  if (seconds !== null) seekTo(seconds)
  jumping.value = false
}

watch(() => [settings.rate, settings.preservePitch], applyRate)
watch(() => settings.volume, applyVolume)
watch(() => settings.enhance, wire)
watch(settings, () => localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)))
watch(
  () => props.src,
  () => {
    failed.value = false
    loopA.value = null
    loopB.value = null
  },
)

onMounted(() => {
  applyRate()
  applyVolume()
})

onBeforeUnmount(() => {
  window.clearTimeout(typingTimer)
  rememberPosition()
  void context?.close()
})
</script>

<template>
  <section class="player select-none rounded-2xl bg-slate-900 p-4 text-slate-100 shadow-sm" aria-label="مشغّل الصوت">
    <audio
      ref="audio"
      :src="src"
      preload="metadata"
      @play="playing = true"
      @pause="((playing = false), rememberPosition())"
      @timeupdate="onTime"
      @loadedmetadata="onLoaded"
      @durationchange="mediaDuration = audio?.duration ?? 0"
      @progress="onProgress"
      @waiting="loading = true"
      @playing="loading = false"
      @canplay="loading = false"
      @error="failed = true"
    />

    <!-- Big clock: click to type a time and jump to it -->
    <div class="tabular flex items-baseline justify-center gap-2" dir="ltr">
      <input
        v-if="jumping"
        ref="jumpInput"
        v-model="jumpText"
        class="w-36 rounded-lg bg-slate-800 px-2 py-1 text-center text-3xl font-semibold text-white outline-none ring-2 ring-brand-500"
        aria-label="انتقال إلى وقت"
        @keydown.enter.prevent="finishJump"
        @keydown.esc.stop.prevent="jumping = false"
        @blur="finishJump"
      />
      <button v-else class="rounded-lg px-2 text-3xl font-semibold tracking-wide hover:bg-slate-800" title="اكتب وقتًا للانتقال إليه" @click="startJump">
        {{ formatClock(current) }}
      </button>
      <span class="text-sm text-slate-400">/ {{ formatClock(duration) }}</span>
    </div>
    <p class="mt-0.5 h-4 text-center text-xs" role="status">
      <span v-if="failed" class="text-red-400">تعذّر تشغيل الصوت</span>
      <span v-else-if="loading && playing" class="text-amber-300">تحميل…</span>
      <span v-else-if="loopA !== null" class="text-amber-300">{{ loopB === null ? 'حدِّد نهاية المقطع المكرَّر' : 'يكرّر المقطع' }}</span>
    </p>

    <!-- Seek bar -->
    <div
      ref="bar"
      class="group relative mt-2 h-3 cursor-pointer rounded-full bg-slate-700"
      dir="ltr"
      role="slider"
      tabindex="0"
      aria-label="موضع التشغيل"
      :aria-valuemin="0"
      :aria-valuemax="Math.round(duration)"
      :aria-valuenow="Math.round(current)"
      :aria-valuetext="formatClock(current)"
      @pointerdown="onBarDown"
      @pointermove="onBarMove"
      @pointerleave="hover = null"
      @keydown="onBarKey"
    >
      <div class="absolute inset-y-0 left-0 rounded-full bg-slate-500/60" :style="{ width: `${buffered}%` }" />
      <div class="absolute inset-y-0 left-0 rounded-full bg-brand-500" :style="{ width: `${progress}%` }" />
      <div class="absolute top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow ring-2 ring-brand-500" :style="{ left: `${progress}%` }" />
      <div
        v-if="loopA !== null && duration > 0"
        class="absolute inset-y-0 border-l-2 border-amber-400 bg-amber-400/30"
        :style="{ left: `${(loopA / duration) * 100}%`, width: loopB === null ? '0' : `${((loopB - loopA) / duration) * 100}%` }"
      />
      <div
        v-if="hover"
        class="tabular pointer-events-none absolute -top-8 z-10 -translate-x-1/2 rounded bg-black px-1.5 py-0.5 text-xs text-white"
        :style="{ left: `${hover.x}px` }"
      >
        {{ formatClock(hover.time) }}
      </div>
    </div>

    <!-- Transport -->
    <div class="mt-4 flex items-center justify-center gap-2" dir="ltr">
      <button class="pbtn" title="رجوع ٣٠ ثانية" aria-label="رجوع ٣٠ ثانية" @click="seekBy(-30)">
        <svg viewBox="0 0 24 24" class="size-5" fill="currentColor"><path d="M11 18V6l-8.5 6zm.5-6 8.5 6V6z" /></svg>
      </button>
      <button class="pbtn tabular" :title="`رجوع ${settings.skip} ثوانٍ`" :aria-label="`رجوع ${settings.skip} ثوانٍ`" @click="seekBy(-settings.skip)">
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5" /></svg>
        <span class="text-xs">{{ settings.skip }}</span>
      </button>
      <button
        class="grid size-14 place-items-center rounded-full bg-brand-500 text-white shadow-lg hover:bg-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
        :title="playing ? 'إيقاف مؤقت' : 'تشغيل'"
        :aria-label="playing ? 'إيقاف مؤقت' : 'تشغيل'"
        @click="toggle"
      >
        <svg v-if="playing" viewBox="0 0 24 24" class="size-7" fill="currentColor"><path d="M6 5h4v14H6zm8 0h4v14h-4z" /></svg>
        <svg v-else viewBox="0 0 24 24" class="size-7 translate-x-0.5" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
      </button>
      <button class="pbtn tabular" :title="`تقديم ${settings.skip} ثوانٍ`" :aria-label="`تقديم ${settings.skip} ثوانٍ`" @click="seekBy(settings.skip)">
        <span class="text-xs">{{ settings.skip }}</span>
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8" /><path d="M21 3v5h-5" /></svg>
      </button>
      <button class="pbtn" title="تقديم ٣٠ ثانية" aria-label="تقديم ٣٠ ثانية" @click="seekBy(30)">
        <svg viewBox="0 0 24 24" class="size-5" fill="currentColor"><path d="m4 18 8.5-6L4 6zm9-12v12l8.5-6z" /></svg>
      </button>
    </div>

    <!-- Speed: one tap presets, plus fine steps -->
    <div class="mt-4">
      <div class="mb-1 flex items-center justify-between text-xs text-slate-400">
        <span>السرعة</span>
        <span class="tabular text-slate-200" dir="ltr">{{ settings.rate.toFixed(2).replace(/0$/, '') }}×</span>
      </div>
      <div class="flex items-center gap-1" dir="ltr">
        <button class="pbtn h-7 min-w-7 px-1" title="إبطاء" aria-label="إبطاء" @click="changeRate(-0.05)">−</button>
        <button
          v-for="rate in QUICK_RATES"
          :key="rate"
          class="tabular h-7 flex-1 rounded-md text-xs"
          :class="settings.rate === rate ? 'bg-brand-500 font-semibold text-white' : 'bg-slate-800 text-slate-200 hover:bg-slate-700'"
          :aria-pressed="settings.rate === rate"
          @click="setRate(rate)"
        >
          {{ rate }}×
        </button>
        <button class="pbtn h-7 min-w-7 px-1" title="تسريع" aria-label="تسريع" @click="changeRate(0.05)">+</button>
      </div>
    </div>

    <!-- Volume -->
    <label class="mt-3 flex items-center gap-2">
      <span class="w-12 text-xs text-slate-400">الصوت</span>
      <input v-model.number="settings.volume" type="range" min="0" :max="MAX_VOLUME" step="0.05" class="flex-1 accent-brand-500" dir="ltr" aria-label="مستوى الصوت (حتى ٣٠٠٪)" />
      <span class="tabular w-10 text-end text-xs" :class="settings.volume > 1 ? 'text-amber-300' : 'text-slate-300'">{{ Math.round(settings.volume * 100) }}%</span>
    </label>

    <!-- Tools -->
    <div class="mt-3 flex flex-wrap gap-1.5">
      <button class="pchip" :class="{ 'pchip-on': loopA !== null }" :title="'يكرّر المقطع بين نقطتين: ' + loopLabel" @click="cycleLoop">{{ loopLabel }}</button>
      <button class="pchip" :class="{ 'pchip-on': settings.enhance }" title="يزيل الطنين ويرفع الكلام الخافت" :aria-pressed="settings.enhance" @click="settings.enhance = !settings.enhance">توضيح الكلام</button>
      <button class="pchip" :class="{ 'pchip-on': settingsOpen }" :aria-expanded="settingsOpen" @click="settingsOpen = !settingsOpen">إعدادات</button>
    </div>

    <div v-if="settingsOpen" class="mt-3 space-y-2 border-t border-slate-700 pt-3 text-sm">
      <label class="flex items-center justify-between gap-2">
        <span>القفز القصير (ثوانٍ)</span>
        <input v-model.number="settings.skip" type="number" min="1" max="60" class="tabular w-16 rounded bg-slate-800 px-2 py-0.5 text-white" />
      </label>
      <label class="flex items-center justify-between gap-2">
        <span>رجوع عند الاستئناف (ثوانٍ)</span>
        <input v-model.number="settings.rewindOnResume" type="number" min="0" max="10" step="0.5" class="tabular w-16 rounded bg-slate-800 px-2 py-0.5 text-white" />
      </label>
      <label class="flex items-center gap-2">
        <input v-model="settings.pauseWhileTyping" type="checkbox" class="size-4 accent-brand-500" />
        <span>إيقاف الصوت أثناء الكتابة ثم استئنافه</span>
      </label>
      <label class="flex items-center gap-2">
        <input v-model="settings.preservePitch" type="checkbox" class="size-4 accent-brand-500" />
        <span>الحفاظ على النبرة عند تغيير السرعة</span>
      </label>
    </div>
  </section>
</template>

<style scoped>
@reference "../../style.css";

.pbtn {
  @apply inline-flex h-10 min-w-10 items-center justify-center gap-0.5 rounded-full text-slate-200 hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-brand-500;
}
.pchip {
  @apply rounded-full border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-brand-500;
}
.pchip-on {
  @apply border-brand-500 bg-brand-700 text-white;
}
</style>
