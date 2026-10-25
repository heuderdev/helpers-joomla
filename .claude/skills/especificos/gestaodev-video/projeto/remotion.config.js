import { Config } from '@remotion/cli/config'

// H.264 + yuv420p é o que o Instagram aceita sem reencodar.
Config.setVideoImageFormat('jpeg')
Config.setPixelFormat('yuv420p')
Config.setCodec('h264')
// CRF 18: praticamente sem perda visível, e o arquivo continua pequeno porque
// o fundo é escuro e chapado.
Config.setCrf(18)

/*
 * Concorrência explícita — a maior alavanca de velocidade que existe aqui.
 *
 * O default do Remotion é conservador (metade dos cores). Medido nesta máquina
 * de 8 cores, num trecho de 60 frames: 13,1s no default contra 6,7s com 8.
 * Quase 2x, sem tocar em nada do conteúdo.
 *
 * Vale mais que qualquer otimização de CSS: o blur(90px) do brilho, que eu
 * suspeitava ser o gargalo, valia só 1,1s desses 13.
 */
Config.setConcurrency(8)

/*
 * Aceleração por hardware DESLIGADA de propósito.
 *
 * O VideoToolbox existe nesta máquina, mas é incompatível com CRF — o Remotion
 * avisa e desliga sozinho. Como o pipeline final reencoda no ffmpeg (para
 * corrigir o range de cor e normalizar o áudio), a qualidade constante do CRF
 * vale mais aqui do que os poucos segundos que o encoder de hardware pouparia.
 *
 * ⚠️ Este arquivo precisa ser `.js`. Com `.mjs` o CLI ignora silenciosamente e
 * volta ao default — foi o que manteve a concorrência em 4x sem aviso nenhum.
 */
