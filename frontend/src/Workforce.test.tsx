import {afterEach, expect, it, vi} from 'vitest'
import {cleanup, render, screen, waitFor} from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import '@testing-library/jest-dom/vitest'
import './testTiming'
import {CompanyWorkspace} from './Workforce'

afterEach(()=>{cleanup();vi.unstubAllGlobals()})
it('captures employment confirmation before asynchronous CSRF refresh',async()=>{
 const person={id:'person',employee_number:'P001',legal_name:'Synthetic Person',preferred_name:null}
 let active=false
 const json=(data:unknown)=>Promise.resolve(new Response(JSON.stringify(data),{headers:{'Content-Type':'application/json'}}))
 const fetcher=vi.fn((path:string,options:RequestInit)=>{
  if(path.endsWith('/capabilities'))return json({data:['workforce.read','workforce.write']})
  if(path.includes('/employees?'))return json({data:[person],meta:{current_page:1,last_page:1,total:1}})
  if(path.endsWith('/employees/person'))return json({data:{employee:person,employments:[{id:'job',employment_number:'E001',start_date:'2026-01-01',end_date:null,status:active?'active':'draft',version:active?2:1}]}})
  if(path==='/sanctum/csrf-cookie')return Promise.resolve(new Response(null,{status:204}))
  if(path.endsWith('/employments/job/activate')){expect(JSON.parse(options.body as string)).toEqual({version:1,reason:'Approved start'});active=true;return json({data:{id:'job',status:'active',version:2}})}
  throw new Error('Unexpected request '+path)
 })
 vi.stubGlobal('fetch',fetcher)
 render(<CompanyWorkspace tenant="tenant-a" company={{id:'company-a',name:'Company A',code:'A'}} onBack={vi.fn()}/>)
 await userEvent.click(await screen.findByRole('button',{name:'View P001'}))
 await userEvent.click(await screen.findByRole('button',{name:'Activate employment'}))
 await userEvent.type(screen.getByLabelText('Reason — avoid confidential personal details'),'Approved start')
 await userEvent.click(screen.getByRole('button',{name:'Confirm activate'}))
 await screen.findByText('active',{exact:true})
 await waitFor(()=>expect(fetcher).toHaveBeenCalledWith('/api/v1/companies/company-a/employments/job/activate',expect.objectContaining({method:'POST',headers:expect.objectContaining({'X-Tenant-ID':'tenant-a'})})))
 expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})
