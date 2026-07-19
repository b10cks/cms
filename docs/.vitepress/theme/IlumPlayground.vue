<script setup lang="ts">
import { computed, ref, watch } from 'vue'

interface DemoImage {
  label: string
  url: string
}

const demoImages: DemoImage[] = [
  {
    label: 'Portrait',
    url: 'https://app.b10cks.com/ilum/01k13p9615ysd6g3zzffkc527j/01k119agamfvn54vb7tnjn87zr/01kxx5prnge07etb8w5682h18v/IMG_1505.jpeg',
  },
  {
    label: 'Landscape',
    url: 'https://app.b10cks.com/ilum/01k13p9615ysd6g3zzffkc527j/01k119agamfvn54vb7tnjn87zr/01kxx5tsg5yheasrm45xr6se1c/0da4f1ff-b0d4-49bc-82e3-fa22945be626.jpg',
  },
]

const source = ref(demoImages[0])

const width = ref<number | null>(600)
const height = ref<number | null>(400)
const cropMode = ref<'none' | 'fill' | 'fit' | 'crop'>('fill')
const gravity = ref<'none' | 'face' | 'center' | 'auto' | 'focal'>('face')
const focalX = ref(50)
const focalY = ref(50)
const cropX = ref(0)
const cropY = ref(0)
const targetW = ref<number | null>(null)
const targetH = ref<number | null>(null)
const format = ref<'default' | 'webp' | 'avif' | 'jpg' | 'png'>('default')
const quality = ref<number | null>(null)

const presets: { label: string, apply: () => void }[] = [
  {
    label: 'Avatar 200×200',
    apply: () => {
      width.value = 200
      height.value = 200
      cropMode.value = 'fill'
      gravity.value = 'face'
    },
  },
  {
    label: 'Hero 1600×900',
    apply: () => {
      width.value = 1600
      height.value = 900
      cropMode.value = 'fill'
      gravity.value = 'auto'
    },
  },
  {
    label: 'OG image 1200×630',
    apply: () => {
      width.value = 1200
      height.value = 630
      cropMode.value = 'fill'
      gravity.value = 'auto'
      format.value = 'jpg'
    },
  },
  {
    label: 'Thumbnail w_320',
    apply: () => {
      width.value = 320
      height.value = null
      cropMode.value = 'none'
      gravity.value = 'none'
    },
  },
]

const operations = computed<string>(() => {
  const ops: string[] = []
  if (width.value) ops.push(`w_${width.value}`)
  if (height.value) ops.push(`h_${height.value}`)

  if (cropMode.value !== 'none') {
    ops.push(`c_${cropMode.value}`)

    if (cropMode.value === 'fill' && gravity.value !== 'none') {
      ops.push(gravity.value === 'focal' ? `g_${focalX.value}p_${focalY.value}p` : `g_${gravity.value}`)
    }

    if (cropMode.value === 'crop') {
      ops.push(`x_${cropX.value}`, `y_${cropY.value}`)
      if (targetW.value && targetH.value) {
        ops.push(`tw_${targetW.value}`, `th_${targetH.value}`)
      }
    }
  }

  return ops.join(',')
})

const queryString = computed<string>(() => {
  const params = new URLSearchParams()
  if (format.value !== 'default') params.set('format', format.value)
  if (quality.value) params.set('quality', String(quality.value))
  const qs = params.toString()
  return qs ? `?${qs}` : ''
})

const validationError = computed<string | null>(() => {
  if ((cropMode.value === 'fill' || cropMode.value === 'crop') && (!width.value || !height.value)) {
    return `c_${cropMode.value} requires both w and h`
  }
  if (cropMode.value === 'fit' && !width.value && !height.value) {
    return 'c_fit requires at least one of w or h'
  }
  if (cropMode.value === 'crop' && ((targetW.value === null) !== (targetH.value === null))) {
    return 'tw and th must be set together'
  }
  return null
})

const url = computed<string>(() => {
  const base = source.value.url
  const path = operations.value ? `${base}/${operations.value}` : base
  return `${path}${queryString.value}`
})

const displayUrl = computed<string>(() => {
  const suffix = (operations.value ? `/${operations.value}` : '') + queryString.value
  return `…/${source.value.url.split('/').pop()}${suffix}`
})

const copied = ref(false)
async function copyUrl(): Promise<void> {
  await navigator.clipboard.writeText(url.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

const loading = ref(false)
const previewUrl = ref(url.value)
let debounce: ReturnType<typeof setTimeout> | undefined

function scheduleUpdate(): void {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    if (!validationError.value && previewUrl.value !== url.value) {
      loading.value = true
      previewUrl.value = url.value
    }
  }, 450)
}

watch(url, scheduleUpdate)
watch(validationError, scheduleUpdate)
</script>

<template>
  <div class="ilum-playground">
    <div class="ilum-toolbar">
      <div class="ilum-field ilum-field--wide">
        <label>Source image</label>
        <div class="ilum-segment">
          <button
            v-for="img in demoImages"
            :key="img.label"
            :class="{ active: source.label === img.label }"
            type="button"
            @click="source = img"
          >
            {{ img.label }}
          </button>
        </div>
      </div>
      <div class="ilum-field ilum-field--wide">
        <label>Presets</label>
        <div class="ilum-presets">
          <button v-for="p in presets" :key="p.label" type="button" @click="p.apply()">
            {{ p.label }}
          </button>
        </div>
      </div>
    </div>

    <div class="ilum-controls">
      <div class="ilum-field">
        <label>Width <code>w</code></label>
        <input v-model.number="width" type="number" min="1" max="5000" placeholder="auto">
      </div>
      <div class="ilum-field">
        <label>Height <code>h</code></label>
        <input v-model.number="height" type="number" min="1" max="5000" placeholder="auto">
      </div>
      <div class="ilum-field">
        <label>Crop mode <code>c</code></label>
        <select v-model="cropMode">
          <option value="none">none (resize)</option>
          <option value="fill">fill</option>
          <option value="fit">fit</option>
          <option value="crop">crop (manual)</option>
        </select>
      </div>
      <div v-if="cropMode === 'fill'" class="ilum-field">
        <label>Gravity <code>g</code></label>
        <select v-model="gravity">
          <option value="none">none</option>
          <option value="face">face</option>
          <option value="center">center</option>
          <option value="auto">auto</option>
          <option value="focal">focal point</option>
        </select>
      </div>
      <template v-if="cropMode === 'fill' && gravity === 'focal'">
        <div class="ilum-field">
          <label>Focal X — {{ focalX }}%</label>
          <input v-model.number="focalX" type="range" min="0" max="100">
        </div>
        <div class="ilum-field">
          <label>Focal Y — {{ focalY }}%</label>
          <input v-model.number="focalY" type="range" min="0" max="100">
        </div>
      </template>
      <template v-if="cropMode === 'crop'">
        <div class="ilum-field">
          <label>Offset X <code>x</code></label>
          <input v-model.number="cropX" type="number" min="0">
        </div>
        <div class="ilum-field">
          <label>Offset Y <code>y</code></label>
          <input v-model.number="cropY" type="number" min="0">
        </div>
        <div class="ilum-field">
          <label>Target width <code>tw</code></label>
          <input v-model.number="targetW" type="number" min="1" placeholder="none">
        </div>
        <div class="ilum-field">
          <label>Target height <code>th</code></label>
          <input v-model.number="targetH" type="number" min="1" placeholder="none">
        </div>
      </template>
      <div class="ilum-field">
        <label>Format</label>
        <select v-model="format">
          <option value="default">default (webp)</option>
          <option value="webp">webp</option>
          <option value="avif">avif</option>
          <option value="jpg">jpg</option>
          <option value="png">png</option>
        </select>
      </div>
      <div class="ilum-field">
        <label>Quality</label>
        <input v-model.number="quality" type="number" min="1" max="100" placeholder="default">
      </div>
    </div>

    <div class="ilum-url">
      <code :title="url">{{ displayUrl }}</code>
      <button type="button" @click="copyUrl">{{ copied ? 'Copied!' : 'Copy full URL' }}</button>
    </div>

    <div v-if="validationError" class="ilum-error">
      {{ validationError }}
    </div>

    <div class="ilum-preview" :class="{ loading }">
      <img :src="previewUrl" alt="Transformed preview" @load="loading = false" @error="loading = false">
    </div>
  </div>
</template>

<style scoped>
.ilum-playground {
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  padding: 20px;
  margin: 24px 0;
  background: var(--vp-c-bg-soft);
}

.ilum-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 16px;
}

.ilum-controls {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px 16px;
}

.ilum-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ilum-field--wide {
  flex: 1 1 260px;
}

.ilum-field label {
  font-size: 12px;
  font-weight: 600;
  color: var(--vp-c-text-2);
}

.ilum-field label code {
  font-size: 11px;
}

.ilum-field input,
.ilum-field select {
  border: 1px solid var(--vp-c-divider);
  border-radius: 6px;
  padding: 6px 8px;
  font-size: 13px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-1);
}

.ilum-field input[type='range'] {
  padding: 0;
  accent-color: var(--vp-c-brand-1);
}

.ilum-segment {
  display: inline-flex;
  border: 1px solid var(--vp-c-divider);
  border-radius: 6px;
  overflow: hidden;
  width: fit-content;
}

.ilum-segment button {
  padding: 6px 14px;
  font-size: 13px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-2);
  cursor: pointer;
}

.ilum-segment button + button {
  border-left: 1px solid var(--vp-c-divider);
}

.ilum-segment button.active {
  background: var(--vp-c-brand-soft);
  color: var(--vp-c-brand-1);
  font-weight: 600;
}

.ilum-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.ilum-presets button {
  padding: 5px 10px;
  font-size: 12px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 6px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-1);
  cursor: pointer;
}

.ilum-presets button:hover {
  border-color: var(--vp-c-brand-1);
  color: var(--vp-c-brand-1);
}

.ilum-url {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 16px;
}

.ilum-url code {
  flex: 1;
  padding: 8px 10px;
  border-radius: 6px;
  background: var(--vp-c-bg);
  border: 1px solid var(--vp-c-divider);
  font-size: 12px;
  overflow-x: auto;
  white-space: nowrap;
}

.ilum-url button {
  flex-shrink: 0;
  padding: 7px 12px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  background: var(--vp-c-brand-1);
  color: var(--vp-c-white);
  cursor: pointer;
}

.ilum-url button:hover {
  background: var(--vp-c-brand-2);
}

.ilum-error {
  margin-top: 10px;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 13px;
  background: var(--vp-c-danger-soft);
  color: var(--vp-c-danger-1);
}

.ilum-preview {
  margin-top: 16px;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 200px;
  border-radius: 8px;
  background:
    repeating-conic-gradient(var(--vp-c-bg) 0% 25%, var(--vp-c-bg-alt) 0% 50%)
    0 0 / 20px 20px;
  border: 1px solid var(--vp-c-divider);
  overflow: hidden;
  transition: opacity 0.2s;
}

.ilum-preview.loading {
  opacity: 0.5;
}

.ilum-preview img {
  max-width: 100%;
  max-height: 480px;
  display: block;
}
</style>
