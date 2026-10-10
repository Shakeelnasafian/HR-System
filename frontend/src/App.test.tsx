import {afterEach, describe, expect, it, vi} from 'vitest'
import {cleanup, render, screen, waitFor} from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import '@testing-library/jest-dom/vitest'
import './testTiming'
import {Workspace} from './App'
import {api} from './api'
afterEach(()=>{cleanup();vi.unstubAllGlobals()})
const user={id:1,name:'Member',email:'member@example.test',mfa_enrolled:false,mfa_verified:false}
const json=(body:unknown,status=200)=>new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}})
describe('tenant context',()=>{
 it('sends context as a selector and includes browser credentials',async()=>{
  const fetcher=vi.fn().mockResolvedValue(json({data:[]}));vi.stubGlobal('fetch',fetcher)
  await api('/api/v1/companies',{tenant:'tenant-a'})
  expect(fetcher).toHaveBeenCalledWith('/api/v1/companies',expect.objectContaining({credentials:'include',headers:expect.objectContaining({'X-Tenant-ID':'tenant-a'})}))
 })
 it('ignores a late response from the previous tenant',async()=>{
  let finishA!:(r:Response)=>void
  vi.stubGlobal('fetch',vi.fn((path:string,options:RequestInit)=>{
   if(path==='/api/v1/me/tenants')return Promise.resolve(json({data:[{id:'a',name:'Tenant A',requires_mfa:false},{id:'b',name:'Tenant B',requires_mfa:false}]}))
   if((options.headers as Record<string,string>)['X-Tenant-ID']==='a')return new Promise<Response>(resolve=>{finishA=resolve})
   return Promise.resolve(json({data:{tenant_id:'b',companies:[{id:'b1',name:'B company',code:'B'}]}}))
  }))
  render(<Workspace user={user} logout={vi.fn()}/>);const select=screen.getByLabelText('Active tenant')
  await screen.findByRole('option',{name:'Tenant A'});await userEvent.selectOptions(select,'a');await userEvent.selectOptions(select,'b')
  await screen.findByText('B company');finishA(json({data:{tenant_id:'a',companies:[{id:'a1',name:'A confidential company',code:'A'}]}}))
  await waitFor(()=>expect(screen.queryByText('A confidential company')).not.toBeInTheDocument())
  expect(screen.getByText('B company')).toBeInTheDocument()
 })
 it('requires MFA before requesting privileged tenant content',async()=>{
  const fetcher=vi.fn().mockResolvedValue(json({data:[{id:'a',name:'Restricted tenant',requires_mfa:true}]}));vi.stubGlobal('fetch',fetcher)
  render(<Workspace user={user} logout={vi.fn()}/>);await screen.findByRole('option',{name:'Restricted tenant'})
  await userEvent.selectOptions(screen.getByLabelText('Active tenant'),'a')
  expect(await screen.findByText('Two-factor authentication')).toBeInTheDocument()
  expect(fetcher).toHaveBeenCalledTimes(1)
 })
})
