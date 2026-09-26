import { chunkedBody } from '../test/utils'
import { readSse } from './sse'

async function collect(chunks: string[]) {
  const events = []
  for await (const e of readSse(chunkedBody(chunks))) events.push(e)
  return events
}

it('parses events split across arbitrary chunk boundaries', async () => {
  const events = await collect(['event: del', 'ta\ndata: {"text":"Hel', 'lo"}\n', '\nevent: done\r\ndata: {}\r\n\r\n'])

  expect(events).toEqual([
    { event: 'delta', data: '{"text":"Hello"}' },
    { event: 'done', data: '{}' },
  ])
})

it('joins multi-line data and defaults the event name', async () => {
  expect(await collect(['data: a\ndata: b\n\n'])).toEqual([{ event: 'message', data: 'a\nb' }])
})

it('handles multibyte characters split between chunks', async () => {
  const bytes = new TextEncoder().encode('event: delta\ndata: "Hyvää päivää"\n\n')
  const body = new ReadableStream<Uint8Array>({
    start(c) {
      c.enqueue(bytes.slice(0, 25))
      c.enqueue(bytes.slice(25))
      c.close()
    },
  })

  const events = []
  for await (const e of readSse(body)) events.push(e)

  expect(events).toEqual([{ event: 'delta', data: '"Hyvää päivää"' }])
})
